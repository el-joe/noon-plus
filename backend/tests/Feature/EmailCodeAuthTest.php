<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\EmailLoginCode;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    Notification::fake();
});

function sendCode(string $email = 'sara@example.com'): string
{
    test()->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => $email])->assertStatus(202);

    $sentCode = null;
    Notification::assertSentTo(new AnonymousNotifiable, EmailLoginCodeNotification::class, function ($notification, $channels, $notifiable) use ($email, &$sentCode): bool {
        $sentCode = $notification->code;

        return $notifiable->routes['mail'] === $email;
    });

    return $sentCode;
}

it('signs a new customer in with the emailed code and creates a verified account', function () {
    $code = sendCode();

    $response = $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
        'email' => 'Sara@Example.com', 'code' => $code, 'device_name' => 'iPhone',
    ]);

    $response->assertOk()->assertJsonPath('data.user.email', 'sara@example.com');
    $user = User::query()->where('email', 'sara@example.com')->sole();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->hasMembership(MembershipRole::Customer))->toBeTrue();

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/uae-en/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'sara@example.com');
});

it('rejects a wrong code with 422 and does not create an account', function () {
    sendCode();

    $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
        'email' => 'sara@example.com', 'code' => '000000', 'device_name' => 'iPhone',
    ])->assertStatus(422)->assertJsonPath('error.code', 'code_invalid');

    expect(User::query()->count())->toBe(0);
});

it('locks the address with 429 after 5 wrong codes, even for the right code', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $code = sendCode();
    $wrongCode = $code === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
            'email' => 'sara@example.com', 'code' => $wrongCode, 'device_name' => 'iPhone',
        ]);
    }

    $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
        'email' => 'sara@example.com', 'code' => $code, 'device_name' => 'iPhone',
    ])->assertStatus(429)->assertJsonPath('error.code', 'code_locked');
});

it('rejects a code after 10 minutes with 422', function () {
    $code = sendCode();
    $this->travel(11)->minutes();

    $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
        'email' => 'sara@example.com', 'code' => $code, 'device_name' => 'iPhone',
    ])->assertStatus(422)->assertJsonPath('error.code', 'code_expired');
});

it('stops an earlier code from working once a new one is sent', function () {
    $firstCode = sendCode();
    Notification::fake();
    $secondCode = sendCode();

    if ($firstCode === $secondCode) {
        $this->markTestSkipped('Both random codes were identical.');
    }

    $this->postJson('/api/v1/uae-en/auth/email-code/verify', [
        'email' => 'sara@example.com', 'code' => $firstCode, 'device_name' => 'iPhone',
    ])->assertStatus(422);

    expect(EmailLoginCode::query()->whereNull('consumed_at')->count())->toBe(1);
});

it('returns 401 for the profile without a token', function () {
    $this->getJson('/api/v1/uae-en/me')->assertUnauthorized();
});

it('throttles a 4th code request for the same email within a minute with 429', function () {
    foreach (range(1, 3) as $request) {
        $this->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => 'sara@example.com'])->assertStatus(202);
    }

    $this->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => 'sara@example.com'])->assertTooManyRequests();
});

it('QA-021: an email-change code is not a sign-in code, and a sign-in code does not replace it', function () {
    [$customer, $token] = customerWithToken();
    $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => 'new@example.com'])->assertStatus(202);
    $changeCode = null;
    Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function (EmailLoginCodeNotification $notification) use (&$changeCode): bool {
        $changeCode = $notification->code;

        return $notification->purpose === 'email_change';
    });
    app('auth')->forgetGuards();
    $this->withoutToken();

    $this->postJson('/api/v1/uae-en/auth/email-code/verify', ['email' => 'new@example.com', 'code' => $changeCode, 'device_name' => 'x'])->assertUnprocessable();
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $this->postJson('/api/v1/uae-en/auth/email-code/send', ['email' => 'new@example.com'])->assertStatus(202);
    $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'new@example.com', 'code' => $changeCode])->assertOk();
    expect($customer->refresh()->email)->toBe('new@example.com');
});
