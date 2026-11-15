<?php

namespace App\Domains\Advertising\Console;

use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:ads:reset-daily-budgets')]
#[Description('ResetAdCampaignDailyBudgetJob (00:00): bill the day\'s ad spend, reset daily caps, resume / end campaigns, in every country')]
class ResetAdBudgetsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AdCampaigns $service): int
    {
        $forEachCountry->run('ad-budget-reset', function (Country $country) use ($service): void {
            $r = $service->resetDaily();
            $this->components->info("{$country->code}: billed {$r['billed']}, resumed {$r['resumed']}, ended {$r['ended']}");
        });

        return self::SUCCESS;
    }
}
