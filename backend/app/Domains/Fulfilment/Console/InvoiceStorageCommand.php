<?php

namespace App\Domains\Fulfilment\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Fulfilment\Support\StorageBilling;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:fbn:invoice-storage {--month= : Y-m to invoice (default: last month)}')]
#[Description('FBN storage invoices (monthly): charge each seller the previous month\'s storage on the next statement, in every country')]
class InvoiceStorageCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, StorageBilling $billing): int
    {
        $forEachCountry->run('fbn-invoice-storage', function (Country $country) use ($billing): void {
            $month = $this->option('month')
                ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), $country->timezone)
                : CarbonImmutable::now($country->timezone)->subMonthNoOverflow();
            $this->components->info("{$country->code}: {$billing->invoice($month)} invoices for {$month->format('Y-m')}");
        });

        return self::SUCCESS;
    }
}
