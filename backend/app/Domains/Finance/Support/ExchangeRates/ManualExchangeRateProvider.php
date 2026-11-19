<?php

namespace App\Domains\Finance\Support\ExchangeRates;

/**
 * Default driver: rates are entered by admins only, nothing is fetched.
 */
class ManualExchangeRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function fetch(array $bases, string $quote): array
    {
        return [];
    }
}
