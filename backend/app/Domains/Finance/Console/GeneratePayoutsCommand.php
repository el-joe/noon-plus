<?php

namespace App\Domains\Finance\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\Payouts;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:payouts:generate {--all : Ignore the vendor schedules and generate for every vendor with an eligible balance}')]
#[Description('Generate vendor payout statements due today (weekly Monday, biweekly 1st & 15th, monthly 1st), in every country (daily 02:00)')]
class GeneratePayoutsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, Payouts $payouts): int
    {
        $forEachCountry->run('generate-vendor-payouts', function (Country $country) use ($payouts): void {
            $this->components->info("{$country->code}: statements {$payouts->generate($country, (bool) $this->option('all'))}");
        });

        return self::SUCCESS;
    }
}
