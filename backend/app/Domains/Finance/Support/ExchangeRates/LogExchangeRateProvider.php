<?php

namespace App\Domains\Finance\Support\ExchangeRates;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: logs what an automatic provider would be asked for
 * and fetches nothing (rates stay admin-maintained).
 */
class LogExchangeRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function fetch(array $bases, string $quote): array
    {
        Log::info('Exchange-rate sync (log driver): no provider configured', ['bases' => $bases, 'quote' => $quote]);

        return [];
    }
}
