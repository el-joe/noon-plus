<?php

use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

function activeDeliveryAgentUser(): User
{
    $user = User::factory()->create(['password' => 'secret-pass-1']);
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => test()->country->id]);
    DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => $user->name, 'status' => 'active']);

    return $user;
}

it('QA-001: refuses a storefront token on the delivery API and accepts only a delivery token', function () {
    $agentUser = activeDeliveryAgentUser();
    $customerToken = $agentUser->createToken('web', ['customer'])->plainTextToken;
    $legacyToken = $agentUser->createToken('legacy')->plainTextToken;

    $this->withToken($customerToken)->getJson('/api/v1/uae-en/delivery/me')->assertForbidden()->assertJsonPath('error.code', 'wrong_token');
    app('auth')->forgetGuards();
    $this->withToken($legacyToken)->getJson('/api/v1/uae-en/delivery/assignments')->assertForbidden();
    app('auth')->forgetGuards();

    $login = $this->postJson('/api/v1/uae-en/delivery/auth/login', ['email' => $agentUser->email, 'password' => 'secret-pass-1', 'device_name' => 'phone'])->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($login->json('data.token'))->getJson('/api/v1/uae-en/delivery/me')->assertOk();
});

it('QA-001: ops groups need their own ops:{role} ability', function () {
    [$seller] = sellerAccount();
    $marketerToken = $seller->createToken('app', ['ops:marketer'])->plainTextToken;
    $customerToken = $seller->createToken('web', ['customer'])->plainTextToken;

    $this->withToken($marketerToken)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($customerToken)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($seller->createToken('app', ['ops:vendor'])->plainTextToken)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertOk();
});

it('QA-004: delivery and ops tokens do not open customer endpoints, customer tokens do', function () {
    $agentUser = activeDeliveryAgentUser();
    [$seller] = sellerAccount();

    foreach ([$agentUser->createToken('d', ['delivery'])->plainTextToken, $seller->createToken('o', ['ops:vendor'])->plainTextToken] as $token) {
        foreach (['me', 'orders', 'addresses', 'me/devices'] as $path) {
            $this->withToken($token)->getJson("/api/v1/uae-en/{$path}")->assertUnauthorized();
            app('auth')->forgetGuards();
        }

        $this->withToken($token)->postJson('/api/v1/uae-en/addresses', ['name' => 'X', 'phone' => '0501112222', 'city' => 'Dubai', 'street' => 'S'])->assertUnauthorized();
        app('auth')->forgetGuards();
    }

    $this->withToken($seller->createToken('web', ['customer'])->plainTextToken)->getJson('/api/v1/uae-en/me')->assertOk();
});

it('issues a scoped customer token with an expiry from the email-code sign-in', function () {
    $this->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => 'sara@example.com'])->assertSuccessful();
    $code = null;
    Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function ($notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });
    $this->postJson('/api/v1/uae-en/auth/email-code/verify', ['email' => 'sara@example.com', 'code' => $code, 'device_name' => 'iPhone'])->assertOk();

    $token = User::query()->where('email', 'sara@example.com')->sole()->tokens()->sole();
    expect($token->abilities)->toBe(['customer'])
        ->and($token->expires_at->between(now()->addDays(89), now()->addDays(91)))->toBeTrue();
});

it('QC-047: expires tokens per type, legacy tokens included', function () {
    [$customer, $token] = customerWithToken();
    $customer->tokens()->update(['created_at' => now()->subDays(91)]);
    $this->withToken($token)->getJson('/api/v1/uae-en/me')->assertUnauthorized();
    app('auth')->forgetGuards();

    $agentUser = activeDeliveryAgentUser();
    $delivery = $agentUser->createToken('d', ['delivery'], now()->addDays(30));
    $this->withToken($delivery->plainTextToken)->getJson('/api/v1/uae-en/delivery/me')->assertOk();
    app('auth')->forgetGuards();
    $this->travel(32)->days();
    $this->withToken($delivery->plainTextToken)->getJson('/api/v1/uae-en/delivery/me')->assertUnauthorized();

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();
    expect($agentUser->tokens()->count())->toBe(0);
});
