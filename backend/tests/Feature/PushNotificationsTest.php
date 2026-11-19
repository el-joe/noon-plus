<?php

use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\Identity\Models\PushDeviceToken;
use App\Shared\Notifications\Push\FcmPushSender;
use App\Shared\Notifications\Push\PushMessage;
use App\Shared\Notifications\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| G-A2: every in-app notification is pushed to the user's registered devices
| of the app that shows it; tokens the provider reports gone are deleted.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

it('pushes a new customer notification to the customer app devices only, and forgets dead tokens', function () {
    [$user, $token] = customerWithToken();
    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('a', 40), 'platform' => 'android'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('b', 40), 'platform' => 'ios'])->assertCreated();
    PushDeviceToken::query()->create(['user_id' => $user->id, 'token' => str_repeat('c', 40), 'token_hash' => PushDeviceToken::hashOf(str_repeat('c', 40)), 'platform' => 'android', 'app' => 'delivery', 'country_code' => 'uae']);
    $sender = app(PushSender::class);
    $sender->invalid = [str_repeat('b', 40)];

    app(CustomerNotifications::class)->notify($user->id, 'order.placed', 'Order NW-1 received', 'We received your order.', '/account/orders/NW-1', ['order_number' => 'NW-1']);

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]->token)->toBe(str_repeat('a', 40))
        ->and($sender->sent[0]->title)->toBe('Order NW-1 received')
        ->and($sender->sent[0]->data)->toMatchArray(['link' => '/account/orders/NW-1', 'type' => 'order.placed', 'order_number' => 'NW-1'])
        ->and(PushDeviceToken::query()->where('token_hash', PushDeviceToken::hashOf(str_repeat('b', 40)))->exists())->toBeFalse()
        ->and(PushDeviceToken::query()->where('app', 'delivery')->exists())->toBeTrue();
});

it('sends through FCM HTTP v1 with a signed service-account token', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/v1/projects/nawy-test/messages:send' => Http::sequence()
            ->push(['name' => 'projects/nawy-test/messages/1'])
            ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    $sender = new FcmPushSender('nawy-test', ['client_email' => 'push@nawy-test.iam.gserviceaccount.com', 'private_key' => $pem]);

    expect($sender->send(new PushMessage('device-1', 'android', 'Hello', 'Body', ['link' => '/x'])))->toBeTrue()
        ->and($sender->send(new PushMessage('device-2', 'ios', 'Hello')))->toBeFalse();

    Http::assertSent(function (Request $request) use ($key): bool {
        if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
            return $request->hasHeader('Authorization', 'Bearer ya29.test') && in_array($request['message']['token'], ['device-1', 'device-2'], true);
        }

        [$header, $claims, $signature] = explode('.', $request['assertion']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

        return $decoded['iss'] === 'push@nawy-test.iam.gserviceaccount.com'
            && openssl_verify($header.'.'.$claims, base64_decode(strtr($signature, '-_', '+/')), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256) === 1;
    });
});
