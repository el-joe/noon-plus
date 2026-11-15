<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:campaigns:monitor-stock')]
#[Description('MonitorCampaignStockJob: pause, resume or complete campaigns by listing stock (hourly), in every country')]
class MonitorCampaignStockCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CampaignStateMachine $campaigns): int
    {
        $forEachCountry->run('campaign-stock-monitor', function (Country $country) use ($campaigns): void {
            $this->components->info("{$country->code}: moved {$campaigns->monitorAvailability()}");
        });

        return self::SUCCESS;
    }
}
