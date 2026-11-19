<?php

namespace App\Domains\Panels\Console;

use App\Domains\Countries\Models\Country;
use App\Shared\Analytics\DailyMetrics;
use App\Shared\Scheduling\ForEachCountry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SRS AggregateAnalyticsCacheJob (hourly; QC-015): recompute today's and
 * yesterday's daily metrics in every country (yesterday catches late events).
 */
#[Signature('nawy:analytics:aggregate {--day= : Recompute one day (Y-m-d) instead}')]
#[Description('Roll orders, GMV, ad and referral events up into daily metrics, in every country (hourly)')]
class AggregateAnalyticsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, DailyMetrics $metrics): int
    {
        $days = $this->option('day') !== null
            ? [CarbonImmutable::parse((string) $this->option('day'))]
            : [CarbonImmutable::now()->subDay(), CarbonImmutable::now()];

        $forEachCountry->run('aggregate-analytics', function (Country $country) use ($metrics, $days): void {
            foreach ($days as $day) {
                $written = $metrics->aggregate($day);
                $this->components->info("{$country->code} {$day->toDateString()}: ".($written['orders.count'] ?? '0').' orders');
            }
        });

        return self::SUCCESS;
    }
}
