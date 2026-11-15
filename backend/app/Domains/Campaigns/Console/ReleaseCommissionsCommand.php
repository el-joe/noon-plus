<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\Clawbacks;
use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:conversions:release')]
#[Description('ReleaseMarketerPendingCommissionJob: move approved commissions to spendable after the clearing days and recover clawbacks (daily 03:30), in every country')]
class ReleaseCommissionsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Conversions $conversions, Clawbacks $clawbacks): int
    {
        $forEachCountry->run('release-commissions', function (Country $country) use ($conversions, $clawbacks): void {
            $this->components->info("{$country->code}: released {$conversions->releaseDue()}, clawbacks checked {$clawbacks->recoverAll($country->currency)}");
        });

        return self::SUCCESS;
    }
}
