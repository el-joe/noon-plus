<?php

namespace App\Domains\Finance\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Support\AcquisitionCommissions;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:finance:acquisition-commissions {--month= : Any date in the month to compute (default: the previous month)}')]
#[Description('Compute acquisition commissions for the previous month in every country (monthly, 1st 02:00 admin time)')]
class GenerateAcquisitionCommissionsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, AcquisitionCommissions $commissions): int
    {
        $month = $this->option('month') ? CarbonImmutable::parse((string) $this->option('month'))->midDay() : null;

        $forEachCountry->run('acquisition-commissions', function (Country $country) use ($commissions, $month): void {
            $day = $month ?? CarbonImmutable::now($country->timezone)->startOfMonth()->subDays(15);
            $this->components->info("{$country->code}: created ".$commissions->generate($day));
        });

        return self::SUCCESS;
    }
}
