<?php

namespace App\Domains\FlashSales\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\FlashSales\Support\FlashSaleScheduler;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:flash-sales:transition')]
#[Description('Open, close, start and end flash sales in every country (runs every 5 minutes)')]
class TransitionFlashSalesCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, FlashSaleScheduler $scheduler): int
    {
        $forEachCountry->run('transition-flash-sales', function (Country $country) use ($scheduler): void {
            $counts = $scheduler->tick();
            $this->components->info($country->code.': '.collect($counts)->map(fn (int $count, string $step): string => "{$step} {$count}")->implode(', '));
        });

        return self::SUCCESS;
    }
}
