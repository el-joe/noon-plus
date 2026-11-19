<?php

namespace App\Domains\Finance\Console;

use App\Domains\Finance\Support\ExchangeRates\ExchangeRates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('nawy:exchange-rates:sync')]
#[Description('Store the latest exchange rates from the configured provider (manual driver: nothing to fetch)')]
class SyncExchangeRatesCommand extends Command
{
    public function handle(ExchangeRates $rates): int
    {
        try {
            $stored = $rates->sync();
        } catch (Throwable $error) {
            report($error);
            $this->components->error('Exchange-rate sync failed: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$rates->providerName()}: ".count($stored).' rates stored');

        return self::SUCCESS;
    }
}
