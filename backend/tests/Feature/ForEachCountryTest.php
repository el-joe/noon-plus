<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryAuditLog;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->setUpCountries());

it('runs the work once in each active country database', function () {
    Country::query()->where('code', 'jor')->update(['is_active' => false]);

    $results = app(ForEachCountry::class)->run('heartbeat', function (Country $country): void {
        CountryAuditLog::query()->create(['action' => 'heartbeat', 'occurred_at' => now()]);
    });

    expect(array_keys($results))->toBe(['uae', 'omn', 'ksa', 'egy', 'kwt', 'qat', 'bhr']);

    $this->actAsCountry('ksa');
    expect(CountryAuditLog::query()->count())->toBe(1);

    $this->actAsCountry('jor');
    expect(CountryAuditLog::query()->count())->toBe(0);
});

it('keeps going when one country fails', function () {
    $results = app(ForEachCountry::class)->run('fragile', function (Country $country): void {
        if ($country->code === 'omn') {
            throw new RuntimeException('Gateway timeout');
        }
    });

    expect($results['omn'])->toBe('failed')
        ->and($results['uae'])->toBe('ok')
        ->and($results['jor'])->toBe('ok');
});
