<?php

use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Models\User;
use App\Shared\Notifications\Sms\SmsSender;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
| Data-migration plan §5.2: a legacy phone-only customer (placeholder email,
| `requires_email`) signs in once with a code sent by SMS, must then add
| and verify an email, and from then on signs in by email.
*/

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    Notification::fake();
    $this->legacyUser = User::factory()->create([
        'email' => 'phone-971501234567@legacy.nawy.invalid', 'phone' => '+971501234567', 'email_verified_at' => null,
    ]);
    $this->legacyUser->forceFill(['requires_email' => true])->save();
});

function smsCode(): string
{
    $sent = app(SmsSender::class)->sent();
    expect($sent)->not->toBeEmpty();
    preg_match('/\d{6}/', end($sent)['message'], $match);

    return $match[0];
}

function legacyPhoneToken(): string
{
    test()->postJson('/api/v1/uae-en/auth/phone-code/send', ['phone' => '050 123 4567'])->assertStatus(202);

    return test()->postJson('/api/v1/uae-en/auth/phone-code/verify', ['phone' => '+971501234567', 'code' => smsCode(), 'device_name' => 'web'])
        ->assertOk()->json('data.token');
}

it('signs a legacy phone-only customer in with an SMS code, then requires an email', function () {
    $this->postJson('/api/v1/uae-ar/auth/phone-code/send', ['phone' => '0501234567'])->assertStatus(202)->assertJsonPath('data.sent', true);
    $sms = app(SmsSender::class)->sent();
    expect($sms)->toHaveCount(1)->and($sms[0]['to'])->toBe('+971501234567')->and($sms[0]['message'])->toContain('ناوي');

    $response = $this->postJson('/api/v1/uae-en/auth/phone-code/verify', ['phone' => '00971 50 123 4567', 'code' => smsCode(), 'device_name' => 'iPhone'])
        ->assertOk()
        ->assertJsonPath('data.user.requires_email', true)
        ->assertJsonPath('data.user.email', 'phone-971501234567@legacy.nawy.invalid');
    $token = $response->json('data.token');

    // Until an email is added, only the account, the email step and sign-out answer.
    $this->withToken($token)->getJson('/api/v1/uae-en/me')->assertOk()->assertJsonPath('data.requires_email', true);
    $this->withToken($token)->getJson('/api/v1/uae-en/orders')->assertForbidden()->assertJsonPath('error.code', 'email_required');
    $this->withToken($token)->getJson('/api/v1/uae-en/categories')->assertOk();
    expect($this->legacyUser->loginEvents()->latest('id')->value('method')?->value)->toBe('phone_code');
});

it('switches to email sign-in once the email is verified, and the phone path closes', function () {
    $token = legacyPhoneToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => 'Sara@Example.com'])->assertStatus(202);
    $code = null;
    Notification::assertSentTo(new AnonymousNotifiable, EmailLoginCodeNotification::class, function ($notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });
    $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'sara@example.com', 'code' => $code])
        ->assertOk()->assertJsonPath('data.email', 'sara@example.com')->assertJsonPath('data.requires_email', false);

    $this->withToken($token)->getJson('/api/v1/uae-en/orders')->assertOk();
    expect($this->legacyUser->fresh())->requires_email->toBeFalse()->email_verified_at->not->toBeNull();

    // The phone path was migration-only: no code is sent any more.
    $before = count(app(SmsSender::class)->sent());
    $this->postJson('/api/v1/uae-en/auth/phone-code/send', ['phone' => '+971501234567'])->assertStatus(202);
    expect(app(SmsSender::class)->sent())->toHaveCount($before);
});

it('answers the same for unknown numbers without sending anything, and rejects wrong codes', function () {
    User::factory()->create(['phone' => '+971509999999']);

    $this->postJson('/api/v1/uae-en/auth/phone-code/send', ['phone' => '+971509999999'])->assertStatus(202)->assertJsonPath('data.sent', true);
    $this->postJson('/api/v1/uae-en/auth/phone-code/send', ['phone' => 'not a phone'])->assertUnprocessable();
    expect(app(SmsSender::class)->sent())->toBe([]);

    $this->postJson('/api/v1/uae-en/auth/phone-code/send', ['phone' => '+971501234567'])->assertStatus(202);
    $this->postJson('/api/v1/uae-en/auth/phone-code/verify', ['phone' => '+971501234567', 'code' => smsCode() === '000000' ? '111111' : '000000', 'device_name' => 'web'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'code_invalid');
    $this->postJson('/api/v1/uae-en/auth/phone-code/verify', ['phone' => '+971509999999', 'code' => '123456', 'device_name' => 'web'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'code_expired');
});
