<?php

use App\Domains\Countries\Models\Country;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

it('lists the 8 active countries with currency decimals and markets', function () {
    $response = $this->getJson(route('api.v1.countries.index'));

    $response->assertOk()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.1.code', 'omn')
        ->assertJsonPath('data.1.currency', ['code' => 'OMR', 'decimals' => 3])
        ->assertJsonPath('data.1.markets', ['omn-ar', 'omn-en']);
});

it('hides inactive countries from the list', function () {
    Country::query()->where('code', 'jor')->update(['is_active' => false]);

    $this->getJson(route('api.v1.countries.index'))->assertJsonCount(7, 'data');
});

it('returns the country and locale of a market', function () {
    $this->getJson('/api/v1/egy-ar/context')
        ->assertOk()
        ->assertJsonPath('data.code', 'egy')
        ->assertJsonPath('data.name', 'مصر')
        ->assertJsonPath('meta.market', 'egy-ar')
        ->assertJsonPath('meta.locale', 'ar');
});

it('returns 404 for an unknown market, an unsupported locale or an inactive country', function (string $market) {
    Country::query()->where('code', 'qat')->update(['is_active' => false]);

    $this->getJson("/api/v1/{$market}/context")->assertNotFound();
})->with(['xyz-en', 'uae-fr', 'qat-en', 'uae']);
