<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Settings\SettingsService;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

it('records who changed a setting with the old and new values', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $settings = app(SettingsService::class);

    $settings->set('commerce.cod_fee', '10.00');
    $settings->set('commerce.cod_fee', '12.00');

    $change = Activity::query()->where('log_name', 'settings')->latest('id')->first();

    expect($change->causer->is($admin))->toBeTrue()
        ->and($change->properties['old']['value'])->toBe('10.00')
        ->and($change->properties['attributes']['value'])->toBe('12.00');
});

it('records membership changes such as a suspension', function () {
    $membership = User::factory()->create()->memberships()->create(['role' => MembershipRole::ProductVendor]);

    $membership->update(['status' => 'suspended']);

    expect(Activity::query()->where('log_name', 'memberships')->where('event', 'updated')->sole()->properties['attributes'])
        ->toBe(['status' => 'suspended']);
});

it('records a country being switched off', function () {
    Country::query()->where('code', 'jor')->sole()->update(['is_active' => false]);

    expect(Activity::query()->where('log_name', 'countries')->where('event', 'updated')->sole()->properties['attributes'])
        ->toBe(['is_active' => false]);
});
