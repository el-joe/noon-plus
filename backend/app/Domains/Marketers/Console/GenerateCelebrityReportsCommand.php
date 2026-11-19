<?php

namespace App\Domains\Marketers\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Marketers\Support\CelebrityReports;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:marketers:celebrity-reports {--period= : Month (Y-m); default = the previous month in each country} {--no-email : Do not email the reports}')]
#[Description('Generate (and email) the celebrity monthly report of every influencer, in every country (monthly, 1st)')]
class GenerateCelebrityReportsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CelebrityReports $reports): int
    {
        $period = $this->option('period');

        if ($period !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
            $this->components->error("Invalid period {$period}");

            return self::INVALID;
        }

        $forEachCountry->run('celebrity-reports', function (Country $country) use ($reports, $period): void {
            $month = $period ?? $reports->previousPeriod();
            $this->components->info("{$country->code} {$month}: ".$reports->generateAll($month, ! $this->option('no-email')).' reports');
        });

        return self::SUCCESS;
    }
}
