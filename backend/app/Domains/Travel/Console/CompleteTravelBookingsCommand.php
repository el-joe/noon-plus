<?php

namespace App\Domains\Travel\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:travel:complete-bookings')]
#[Description('CompleteTravelBookingsJob (03:45): complete trips whose return date passed and expire packages without future departures, in every country')]
class CompleteTravelBookingsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, TravelBookings $service): int
    {
        $forEachCountry->run('travel-complete', function (Country $country) use ($service): void {
            $completed = $service->completeDue();
            $expired = app(TravelPackages::class)->expireDue();
            $this->components->info("{$country->code}: completed {$completed}, packages expired {$expired}");
        });

        return self::SUCCESS;
    }
}
