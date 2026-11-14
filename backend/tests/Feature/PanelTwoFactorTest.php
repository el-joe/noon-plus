<?php

use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    $this->withoutVite();
    Notification::fake();
});

function partnerUrl(string $path = ''): string
{
    return 'http://partner.'.config('nawy.base_domain').'/'.ltrim($path, '/');
}

function vendorWithTwoStep(): User
{
    $user = User::factory()->create(['email' => 'khalid@soundmax.ae', 'password' => 'a-long-password', 'two_factor_enabled' => true]);
    $user->memberships()->create(['role' => MembershipRole::ProductVendor]);

    return $user;
}

function emailedCode(): string
{
    $code = null;
    Notification::assertSentTo(new AnonymousNotifiable, EmailLoginCodeNotification::class, function ($notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    return $code;
}

it('asks for an emailed code before signing in a two-step account', function () {
    $vendor = vendorWithTwoStep();

    $this->post(partnerUrl('login'), ['email' => 'khalid@soundmax.ae', 'password' => 'a-long-password'])
        ->assertRedirect(partnerUrl('two-factor'));
    $this->assertGuest();

    $this->get(partnerUrl('two-factor'))->assertOk()->assertSee('k•••••@soundmax.ae');

    $this->post(partnerUrl('two-factor'), ['code' => emailedCode()])
        ->assertRedirect(rtrim(partnerUrl(), '/'));
    $this->assertAuthenticatedAs($vendor);

    expect($vendor->loginEvents()->pluck('outcome')->all())
        ->toBe([LoginOutcome::SecondStepSent, LoginOutcome::Succeeded]);
});

it('keeps the user signed out after a wrong second-step code', function () {
    vendorWithTwoStep();
    $this->post(partnerUrl('login'), ['email' => 'khalid@soundmax.ae', 'password' => 'a-long-password']);
    $wrongCode = emailedCode() === '111111' ? '222222' : '111111';

    $this->post(partnerUrl('two-factor'), ['code' => $wrongCode])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(LoginEvent::query()->where('outcome', LoginOutcome::WrongCode)->count())->toBe(1);
});

it('sends the code page back to sign-in when no password step happened', function () {
    $this->get(partnerUrl('two-factor'))->assertRedirect(partnerUrl('login'));
});

it('records wrong passwords and missing panel access in the login history', function () {
    vendorWithTwoStep();
    $customer = User::factory()->create(['email' => 'sara@example.com', 'password' => 'customer-password']);

    $this->post(partnerUrl('login'), ['email' => 'khalid@soundmax.ae', 'password' => 'wrong']);
    $this->post(partnerUrl('login'), ['email' => 'sara@example.com', 'password' => 'customer-password']);

    expect(LoginEvent::query()->orderBy('id')->get()->map(fn ($event) => [$event->email, $event->outcome])->all())
        ->toBe([
            ['khalid@soundmax.ae', LoginOutcome::WrongCredentials],
            ['sara@example.com', LoginOutcome::NoPanelAccess],
        ]);
    expect(LoginEvent::query()->where('user_id', $customer->id)->exists())->toBeTrue();
});
