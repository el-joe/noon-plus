<?php

namespace App\Domains\Panels\Console;

use App\Domains\Countries\Models\Country;
use App\Domains\Onboarding\Support\PortalFunnel;
use App\Domains\Settings\SettingsService;
use App\Shared\Analytics\DailyMetrics;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SRS `portal-analytics:prune` (daily 04:30; QC-015): raw ad events older
 * than `analytics.raw_retention_days` (default 180) are deleted, and so are
 * portal funnel events (FR-POR-8); the daily rollups keep their totals.
 */
#[Signature('nawy:analytics:prune')]
#[Description('Delete raw analytics events past the retention window, in every country (daily 04:30)')]
class PruneAnalyticsCommand extends Command
{
    public function handle(ForEachCountry $forEachCountry, DailyMetrics $metrics, SettingsService $settings, PortalFunnel $funnel): int
    {
        $forEachCountry->run('prune-analytics', function (Country $country) use ($metrics, $settings, $funnel): void {
            $days = max(30, (int) $settings->get('analytics.raw_retention_days', config('nawy.analytics.raw_retention_days'), $country));
            $this->components->info("{$country->code}: ".$metrics->prune($days).' raw events pruned');
            $this->components->info("{$country->code}: ".$funnel->prune($days).' portal funnel events pruned');
        });

        return self::SUCCESS;
    }
}
