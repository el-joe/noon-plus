<?php

namespace App\Domains\Finance\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:subscriptions:bill')]
#[Description('Renew and invoice vendor plans at period end, move unpaid ones to past_due and expire them after the grace days, in every country (daily)')]
class BillVendorSubscriptionsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, VendorSubscriptions $subscriptions): int
    {
        $forEachCountry->run('vendor-subscriptions', function (Country $country) use ($subscriptions): void {
            $counts = $subscriptions->bill();
            $this->components->info("{$country->code}: ".collect($counts)->map(fn (int $count, string $key): string => "{$key} {$count}")->implode(', '));
        });

        return self::SUCCESS;
    }
}
