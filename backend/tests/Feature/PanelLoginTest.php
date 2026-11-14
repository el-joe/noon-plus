<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CountrySeeder::class);
    $this->withoutVite();
});

function adminUrl(string $path = ''): string
{
    return 'http://admin.'.config('nawy.base_domain').'/'.ltrim($path, '/');
}

function adminUser(): User
{
    $user = User::factory()->create(['email' => 'ops@nawy.test', 'password' => 'correct-horse-battery']);
    $user->memberships()->create(['role' => MembershipRole::Admin]);

    return $user;
}

it('redirects a guest from the admin dashboard to the admin sign-in page', function () {
    $this->get(adminUrl())->assertRedirect(adminUrl('login'));
});

it('signs an admin in and shows the dashboard with all 8 country clocks', function () {
    adminUser();

    $this->post(adminUrl('login'), ['email' => 'ops@nawy.test', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('admin.dashboard'));

    $this->get(adminUrl())
        ->assertOk()
        ->assertSee('Platform overview')
        ->assertSee('Asia/Muscat')
        ->assertSeeInOrder(['United Arab Emirates', 'Oman', 'Saudi Arabia', 'Egypt', 'Kuwait', 'Qatar', 'Bahrain', 'Jordan']);
});

it('rejects a wrong password without signing in', function () {
    adminUser();

    $this->post(adminUrl('login'), ['email' => 'ops@nawy.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('does not let a customer into the admin panel', function () {
    $customer = User::factory()->create(['email' => 'sara@example.com', 'password' => 'customer-password']);
    $customer->memberships()->create(['role' => MembershipRole::Customer]);

    $this->post(adminUrl('login'), ['email' => 'sara@example.com', 'password' => 'customer-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($customer)->get(adminUrl())->assertForbidden();
});

it('signs the admin out', function () {
    $this->actingAs(adminUser())->post(adminUrl('logout'))->assertRedirect(adminUrl('login'));

    $this->assertGuest();
});
