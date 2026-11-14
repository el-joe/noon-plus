<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryClock;
use Carbon\CarbonImmutable;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

function country(string $code): Country
{
    return Country::query()->where('code', $code)->sole();
}

it('labels an Egypt 10 PM November flash sale as midnight next day in Oman time', function () {
    $label = app(CountryClock::class)->adminTimeLabel(country('egy'), '2026-11-20 22:00');

    expect($label)->toBe('= 12:00 AM (+1) Oman time');
});

it('labels an Egypt 10 PM summer time as 11 PM the same day in Oman time', function () {
    $label = app(CountryClock::class)->adminTimeLabel(country('egy'), '2026-07-20 22:00');

    expect($label)->toBe('= 11:00 PM Oman time');
});

it('shows a KSA 8 PM time as 9 PM in Oman time', function () {
    expect(app(CountryClock::class)->adminTimeLabel(country('ksa'), '2026-11-21 20:00'))
        ->toBe('= 9:00 PM Oman time');
});

it('stores a country local time as UTC', function () {
    $utc = app(CountryClock::class)->localToUtc(country('uae'), '2026-11-14 16:00');

    expect($utc->toDateTimeString())->toBe('2026-11-14 12:00:00')
        ->and($utc->timezoneName)->toBe('UTC');
});

it('uses each country\'s own weekend', function (string $code, string $date, bool $isWeekend) {
    $moment = CarbonImmutable::parse($date.' 12:00', country($code)->timezone);

    expect(app(CountryClock::class)->isWeekend(country($code), $moment))->toBe($isWeekend);
})->with([
    'UAE Sunday is weekend' => ['uae', '2026-11-15', true],
    'UAE Friday is a working day' => ['uae', '2026-11-13', false],
    'Oman Friday is weekend' => ['omn', '2026-11-13', true],
    'Oman Sunday is a working day' => ['omn', '2026-11-15', false],
]);
