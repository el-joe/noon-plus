<?php

namespace App\Domains\Orders\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Orders\Support\AutoCompleteDeliveredOrders;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:orders:auto-complete')]
#[Description('Complete delivered sub-orders whose return window has passed, in every country (daily 02:00)')]
class AutoCompleteOrdersCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AutoCompleteDeliveredOrders $autoComplete): int
    {
        $forEachCountry->run('auto-complete-orders', function (Country $country) use ($autoComplete): void {
            $this->components->info("{$country->code}: completed {$autoComplete->run($country)}");
        });

        return self::SUCCESS;
    }
}
