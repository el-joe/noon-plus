<?php

use App\Broadcasting\CountryOpsChannel;
use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

function memberOf(MembershipRole $role, ?string $countryCode = null): User
{
    $user = User::factory()->create();
    $user->memberships()->create([
        'role' => $role,
        'country_id' => $countryCode ? Country::query()->where('code', $countryCode)->value('id') : null,
    ]);

    return $user;
}

it('opens the Horizon dashboard only to admins', function () {
    expect(Gate::forUser(memberOf(MembershipRole::Admin))->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(memberOf(MembershipRole::ProductVendor))->allows('viewHorizon'))->toBeFalse();
});

it('lets admins join every country ops channel', function () {
    $admin = memberOf(MembershipRole::Admin);

    expect((new CountryOpsChannel)->join($admin, 'uae'))->toBeTrue()
        ->and((new CountryOpsChannel)->join($admin, 'jor'))->toBeTrue();
});

it('limits a carrier supervisor to the ops channel of their own country', function () {
    $supervisor = memberOf(MembershipRole::CarrierSupervisor, 'omn');

    expect((new CountryOpsChannel)->join($supervisor, 'omn'))->toBeTrue()
        ->and((new CountryOpsChannel)->join($supervisor, 'uae'))->toBeFalse();
});

it('refuses ops channels for customers and for unknown or inactive countries', function () {
    Country::query()->where('code', 'bhr')->update(['is_active' => false]);

    expect((new CountryOpsChannel)->join(memberOf(MembershipRole::Customer, 'uae'), 'uae'))->toBeFalse()
        ->and((new CountryOpsChannel)->join(memberOf(MembershipRole::Admin), 'xyz'))->toBeFalse()
        ->and((new CountryOpsChannel)->join(memberOf(MembershipRole::Admin), 'bhr'))->toBeFalse();
});
