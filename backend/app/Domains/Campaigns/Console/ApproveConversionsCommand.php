<?php

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Countries\Models\Country;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:conversions:approve')]
#[Description('ApproveMarketerConversionsJob: approve conversions whose return window has passed (daily 03:15), in every country')]
class ApproveConversionsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Conversions $conversions): int
    {
        $forEachCountry->run('approve-conversions', function (Country $country) use ($conversions): void {
            $this->components->info("{$country->code}: approved {$conversions->approveDue()}");
        });

        return self::SUCCESS;
    }
}
