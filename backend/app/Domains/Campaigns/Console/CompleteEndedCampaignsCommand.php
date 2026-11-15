<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:campaigns:complete-ended')]
#[Description('CompleteEndedMarketerCampaignsJob: complete campaigns past their end date and settle influencers (daily 04:00), in every country')]
class CompleteEndedCampaignsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CampaignStateMachine $campaigns): int
    {
        $forEachCountry->run('campaign-complete-ended', function (Country $country) use ($campaigns): void {
            $this->components->info("{$country->code}: completed {$campaigns->completeEnded()}");
        });

        return self::SUCCESS;
    }
}
