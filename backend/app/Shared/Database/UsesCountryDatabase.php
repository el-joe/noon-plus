<?php

namespace App\Shared\Database;

use App\Domains\Countries\Support\CountryContext;

/**
 * For models whose table lives in a country database. The connection is taken
 * from the current {@see CountryContext}; without a country it fails loudly.
 */
trait UsesCountryDatabase
{
    public function getConnectionName(): ?string
    {
        return app(CountryContext::class)->connectionName();
    }
}
