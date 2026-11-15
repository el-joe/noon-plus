<?php

namespace App\Domains\Travel\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Travel\Support\TravelBookings;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:travel:expire-holds')]
#[Description('Travel seat-hold expiry (every minute): unpaid holds past their time release their seats, in every country')]
class ExpireSeatHoldsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, TravelBookings $service): int
    {
        $forEachCountry->run('travel-seat-holds', function (Country $country) use ($service): void {
            $this->components->info("{$country->code}: expired {$service->expireHolds()}");
        });

        return self::SUCCESS;
    }
}
