<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:campaigns:auto-approve')]
#[Description('ProcessCampaignAutoApproveJob: approve campaigns still pending after marketer_campaign_auto_approve_hours, in every country')]
class AutoApproveCampaignsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CampaignStateMachine $campaigns): int
    {
        $forEachCountry->run('campaign-auto-approve', function (Country $country) use ($campaigns): void {
            $this->components->info("{$country->code}: auto-approved {$campaigns->autoApproveDue()}");
        });

        return self::SUCCESS;
    }
}
