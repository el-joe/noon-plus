<?php

use App\Domains\Countries\Models\Country;
use App\View\Components\Ui\DatetimeInput;
use Carbon\CarbonImmutable;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

it('shows a stored UTC time in the country local time with the Oman equivalent', function () {
    $egypt = Country::query()->where('code', 'egy')->sole();

    $view = $this->component(DatetimeInput::class, [
        'label' => 'Flash sale starts',
        'name' => 'starts_at',
        'country' => $egypt,
        'value' => CarbonImmutable::parse('2026-11-20 20:00', 'UTC'),
    ]);

    $view->assertSee('value="2026-11-20T22:00"', false)
        ->assertSee('= 12:00 AM (+1) Oman time')
        ->assertSee('Africa/Cairo');
});

it('asks for a time when the field is empty', function () {
    $view = $this->component(DatetimeInput::class, [
        'label' => 'Starts',
        'name' => 'starts_at',
        'country' => Country::query()->where('code', 'uae')->sole(),
    ]);

    $view->assertSee('Pick a time to see the Oman equivalent');
});
