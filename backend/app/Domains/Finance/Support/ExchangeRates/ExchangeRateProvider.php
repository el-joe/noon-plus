<?php

namespace App\Domains\Finance\Support\ExchangeRates;

/**
 * Optional automatic source of exchange rates (`nawy.exchange_rates.driver`).
 * `manual` (default) and `log` fetch nothing — admins keep the rates in
 * Admin → Countries → Exchange rates; `openexchangerates` needs the owner's
 * App ID (`NAWY_EXCHANGE_RATES_APP_ID`, docs/deploy/exchange-rates.md).
 */
interface ExchangeRateProvider
{
    /**
     * Short name stored as the rate `source` (e.g. "openexchangerates").
     */
    public function name(): string;

    /**
     * Latest rates for `1 base = rate quote`, keyed by base currency, as
     * decimal strings. Currencies the provider does not know are left out.
     *
     * @param  array<int, string>  $bases
     * @return array<string, string>
     */
    public function fetch(array $bases, string $quote): array;
}
