<?php

use App\Domains\Countries\Exceptions\CountryContextMissing;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryAuditLog;
use App\Domains\Countries\Support\CountryContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->setUpCountries());

it('provisions one isolated database connection for each of the 8 countries', function () {
    expect(Country::query()->active()->pluck('code')->all())
        ->toBe(['uae', 'omn', 'ksa', 'egy', 'kwt', 'qat', 'bhr', 'jor']);

    Country::query()->each(function (Country $country): void {
        expect(Schema::connection($country->connectionName())->hasTable('country_audit_logs'))->toBeTrue();
    });
});

it('does not show one country\'s records to another country', function () {
    $this->actAsCountry('uae');
    CountryAuditLog::query()->create(['action' => 'order.placed', 'occurred_at' => now()]);

    $this->actAsCountry('omn');
    expect(CountryAuditLog::query()->count())->toBe(0);

    CountryAuditLog::query()->create(['action' => 'order.placed', 'occurred_at' => now()]);
    CountryAuditLog::query()->create(['action' => 'order.cancelled', 'occurred_at' => now()]);

    $this->actAsCountry('uae');
    expect(CountryAuditLog::query()->count())->toBe(1);
});

it('restores the previous country after running as another one', function () {
    $context = app(CountryContext::class);
    $uae = $this->actAsCountry('uae');
    $egypt = Country::query()->where('code', 'egy')->sole();

    $seenCode = $context->runAs($egypt, fn (Country $country): string => $context->current()->code);

    expect($seenCode)->toBe('egy')
        ->and($context->current()->is($uae))->toBeTrue();
});

it('refuses country-database queries when no country is selected', function () {
    CountryAuditLog::query()->count();
})->throws(CountryContextMissing::class);
