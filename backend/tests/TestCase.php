<?php

namespace Tests;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use Database\Seeders\CountrySeeder;
use Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test using RefreshDatabase runs inside a transaction on the central
     * database and on each of the 8 country databases.
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [
        null,
        'country_uae', 'country_omn', 'country_ksa', 'country_egy',
        'country_kwt', 'country_qat', 'country_bhr', 'country_jor',
        'reporting',
    ];

    private static bool $countryDatabasesMigrated = false;

    /**
     * Migrate the country databases once per test run, before the traits
     * (RefreshDatabase) open their transactions.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        if (! self::$countryDatabasesMigrated) {
            Artisan::call('nawy:countries:migrate', ['--fresh' => true]);
            Artisan::call('nawy:reporting:migrate', ['--fresh' => true]);
            self::$countryDatabasesMigrated = true;
        }

        return parent::setUpTraits();
    }

    /**
     * Seed the 8 countries and their default payment gateways (MASTER_PLAN §4a)
     * in the central database.
     */
    protected function setUpCountries(): void
    {
        $this->seed([CountrySeeder::class, PaymentGatewaySeeder::class]);
    }

    /**
     * Act as one country for the rest of the test.
     */
    protected function actAsCountry(string $code): Country
    {
        $country = Country::query()->where('code', $code)->firstOrFail();
        app(CountryContext::class)->set($country);

        return $country;
    }
}
