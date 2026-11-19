<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Domains\Panels\Notifications\PanelPasswordResetNotification;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    $this->withoutVite();
    Notification::fake();
});

function panelHostUrl(string $panel, string $path = ''): string
{
    return 'http://'.config("nawy.panels.{$panel}.host").'.'.config('nawy.base_domain').'/'.ltrim($path, '/');
}

dataset('business panels', [
    'admin' => ['admin', MembershipRole::Admin],
    'partner' => ['partner', MembershipRole::ProductVendor],
    'marketer' => ['marketer', MembershipRole::Influencer],
    'travel' => ['travel_agency', MembershipRole::TravelAgency],
    'carrier' => ['carrier', MembershipRole::CarrierSupervisor],
    'delivery' => ['delivery', MembershipRole::DeliveryAgent],
]);

it('resets a panel password by emailed link on every business panel', function (string $panel, MembershipRole $role) {
    $user = User::factory()->create(['email' => 'staff@nawy.test', 'password' => 'old-password']);
    $user->memberships()->create(['role' => $role]);

    $this->get(panelHostUrl($panel, 'login'))->assertOk()->assertSee(panelHostUrl($panel, 'forgot-password'), false);
    $this->get(panelHostUrl($panel, 'forgot-password'))->assertOk();
    $this->from(panelHostUrl($panel, 'forgot-password'))->post(panelHostUrl($panel, 'forgot-password'), ['email' => 'Staff@nawy.test'])
        ->assertRedirect(panelHostUrl($panel, 'forgot-password'))->assertSessionHas('status');

    $url = null;
    Notification::assertSentTo($user, PanelPasswordResetNotification::class, function ($notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });
    expect($url)->toStartWith(panelHostUrl($panel, 'reset-password/'));
    $token = basename(parse_url($url, PHP_URL_PATH));

    $this->get($url)->assertOk()->assertSee('staff@nawy.test');
    $this->post(panelHostUrl($panel, 'reset-password'), ['token' => 'wrong', 'email' => 'staff@nawy.test', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertSessionHasErrors('email');
    $this->post(panelHostUrl($panel, 'reset-password'), ['token' => $token, 'email' => 'staff@nawy.test', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertRedirect(panelHostUrl($panel, 'login'));

    $this->assertGuest();
    expect(Hash::check('new-password-1', $user->refresh()->password))->toBeTrue();
    // The link works once.
    $this->post(panelHostUrl($panel, 'reset-password'), ['token' => $token, 'email' => 'staff@nawy.test', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2'])
        ->assertSessionHasErrors('email');
})->with('business panels');

it('mails nothing to accounts without access to the panel and answers the same', function () {
    User::factory()->create(['email' => 'customer@nawy.test'])->memberships()->create(['role' => MembershipRole::Customer]);

    $this->post(panelHostUrl('admin', 'forgot-password'), ['email' => 'customer@nawy.test'])->assertSessionHas('status');
    $this->post(panelHostUrl('admin', 'forgot-password'), ['email' => 'nobody@nawy.test'])->assertSessionHas('status');

    Notification::assertNothingSent();
});

it('keeps two-step sign-in after a reset', function () {
    $user = User::factory()->create(['email' => 'khalid@soundmax.ae', 'password' => 'old-password', 'two_factor_enabled' => true]);
    $user->memberships()->create(['role' => MembershipRole::ProductVendor]);
    $this->post(panelHostUrl('partner', 'forgot-password'), ['email' => 'khalid@soundmax.ae']);
    $url = null;
    Notification::assertSentTo($user, PanelPasswordResetNotification::class, function ($notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });
    $this->post(panelHostUrl('partner', 'reset-password'), ['token' => basename(parse_url($url, PHP_URL_PATH)), 'email' => 'khalid@soundmax.ae', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);

    $this->post(panelHostUrl('partner', 'login'), ['email' => 'khalid@soundmax.ae', 'password' => 'new-password-1'])
        ->assertRedirect(panelHostUrl('partner', 'two-factor'));
    $this->assertGuest();
    Notification::assertSentTo(new AnonymousNotifiable, EmailLoginCodeNotification::class);
});

it('throttles panel reset requests per email', function () {
    foreach (range(1, 3) as $attempt) {
        $this->post(panelHostUrl('admin', 'forgot-password'), ['email' => 'someone@nawy.test'])->assertRedirect();
    }

    $this->post(panelHostUrl('admin', 'forgot-password'), ['email' => 'someone@nawy.test'])->assertStatus(429);
});
