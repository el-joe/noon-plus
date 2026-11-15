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

it('signs each business role into its own panel', function (string $host, MembershipRole $role, string $panelName) {
    $user = User::factory()->create(['email' => 'member@example.com', 'password' => 'a-long-password']);
    $user->memberships()->create(['role' => $role]);

    $this->post(onPanel($host, 'login'), ['email' => 'member@example.com', 'password' => 'a-long-password'])
        ->assertRedirect(rtrim(onPanel($host), '/'));

    $this->get(onPanel($host))->assertOk()->assertSee($panelName)->assertSee($role->value);
})->with([
    'product vendor' => ['partner', MembershipRole::ProductVendor, 'Seller Center'],
    'classified seller' => ['partner', MembershipRole::ClassifiedSeller, 'Seller Center'],
    'affiliate' => ['marketer', MembershipRole::Affiliate, 'Creator Studio'],
    'broker' => ['marketer', MembershipRole::Broker, 'Creator Studio'],
    'travel agency' => ['travel-agency', MembershipRole::TravelAgency, 'Travel Partner'],
    'carrier supervisor' => ['carrier', MembershipRole::CarrierSupervisor, 'Shipping'],
    'delivery agent' => ['delivery', MembershipRole::DeliveryAgent, 'Delivery'],
]);

it('forbids a member of one panel from opening another panel', function () {
    $vendor = User::factory()->create();
    $vendor->memberships()->create(['role' => MembershipRole::ProductVendor]);

    $this->actingAs($vendor)->get(onPanel('marketer'))->assertForbidden();
    $this->actingAs($vendor)->get(onPanel('admin'))->assertForbidden();
});

it('sends a guest to the sign-in page of the panel they tried to open', function () {
    $this->get(onPanel('travel-agency'))->assertRedirect(onPanel('travel-agency', 'login'));
});

it('ignores suspended memberships', function () {
    $vendor = User::factory()->create();
    $vendor->memberships()->create(['role' => MembershipRole::ProductVendor, 'status' => 'suspended']);

    $this->actingAs($vendor)->get(onPanel('partner'))->assertForbidden();
});
