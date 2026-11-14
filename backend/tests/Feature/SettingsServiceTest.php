<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Settings\SettingsService;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

it('returns the country value over the global value and the default when neither exists', function () {
    $settings = app(SettingsService::class);
    $oman = Country::query()->where('code', 'omn')->sole();
    $uae = Country::query()->where('code', 'uae')->sole();

    expect($settings->get('commerce.cod_fee', '0.000', $oman))->toBe('0.000');

    $settings->set('commerce.cod_fee', '10.00');
    $settings->set('commerce.cod_fee', '1.500', $oman);

    expect($settings->get('commerce.cod_fee', null, $oman))->toBe('1.500')
        ->and($settings->get('commerce.cod_fee', null, $uae))->toBe('10.00');
});

it('returns a new value right after it is changed', function () {
    $settings = app(SettingsService::class);

    $settings->set('commerce.cart_lock_minutes', 15);
    expect($settings->get('commerce.cart_lock_minutes'))->toBe(15);

    $settings->set('commerce.cart_lock_minutes', 20);
    expect($settings->get('commerce.cart_lock_minutes'))->toBe(20);
});
