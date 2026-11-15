<?php

namespace App\Domains\Delivery\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Delivery\Support\SlaMonitor;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:delivery:check-sla')]
#[Description('Flag open delivery assignments past their SLA, in every country (every 15 minutes)')]
class CheckSlaBreachesCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, SlaMonitor $monitor): int
    {
        $forEachCountry->run('check-delivery-sla', function (Country $country) use ($monitor): void {
            $this->components->info("{$country->code}: flagged ".$monitor->check());
        });

        return self::SUCCESS;
    }
}
