<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:ads:schedule-bookings')]
#[Description('PaidAdSchedulerJob (every 5 min): schedule, start, complete and expire ad bookings, in every country')]
class ScheduleAdBookingsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AdBookings $service): int
    {
        $forEachCountry->run('ad-bookings', function (Country $country) use ($service): void {
            $r = $service->schedule();
            $this->components->info("{$country->code}: ".json_encode($r));
        });

        return self::SUCCESS;
    }
}
