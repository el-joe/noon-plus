<?php

namespace App\Domains\Marketers\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Marketers\Support\CrossPromotion;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nawy:marketers:evaluate-cross-promotion {--period= : YYYY-MM, default the previous month}')]
#[Description('Cross-promotion obligation evaluation for the previous month (monthly), in every country')]
class EvaluateCrossPromotionCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, CrossPromotion $crossPromotion): int
    {
        $forEachCountry->run('cross-promotion-evaluation', function (Country $country) use ($crossPromotion): void {
            $period = $this->option('period') ?: CarbonImmutable::now($country->timezone)->subMonthNoOverflow()->format('Y-m');
            $this->components->info("{$country->code}: evaluated {$crossPromotion->evaluate($period)}");
        });

        return self::SUCCESS;
    }
}
