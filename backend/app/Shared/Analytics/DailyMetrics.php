<?php

namespace App\Shared\Analytics;

use App\Domains\Countries\Support\CountryContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics rollup (SRS AggregateAnalyticsCacheJob, hourly; QC-015) for the
 * current country: per day, orders and GMV (by currency; cancelled orders
 * excluded), ad impressions / clicks / spend and marketer referral clicks.
 * Recomputing a day replaces its rows, so the job is idempotent.
 */
class DailyMetrics
{
    public function __construct(private CountryContext $countryContext) {}

    private function db(): Connection
    {
        return DB::connection($this->countryContext->connectionName());
    }

    /**
     * @return array<string, string> metric|dimension => value written
     */
    public function aggregate(CarbonImmutable $day): array
    {
        $from = $day->startOfDay();
        $to = $from->addDay();
        $rows = [];

        $orders = $this->db()->table('orders')
            ->where('placed_at', '>=', $from)->where('placed_at', '<', $to)->where('status', '!=', 'cancelled')
            ->selectRaw('currency, count(*) as orders, coalesce(sum(grand_total), 0) as gmv')->groupBy('currency')->get();

        $rows[] = ['orders.count', '', (string) $orders->sum('orders')];

        foreach ($orders as $row) {
            $rows[] = ['orders.gmv', $row->currency, (string) $row->gmv];
        }

        $ads = $this->db()->table('ad_events')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('type, count(*) as total, coalesce(sum(case when is_billable then cost else 0 end), 0) as spend')->groupBy('type')->get()->keyBy('type');

        $rows[] = ['ads.impressions', '', (string) ($ads['impression']->total ?? 0)];
        $rows[] = ['ads.clicks', '', (string) ($ads['click']->total ?? 0)];
        $rows[] = ['ads.spend', '', (string) $ads->sum('spend')];
        $rows[] = ['referrals.clicks', '', (string) $this->db()->table('referral_clicks')->where('clicked_at', '>=', $from)->where('clicked_at', '<', $to)->count()];

        $written = [];

        $this->db()->transaction(function () use ($rows, $from, &$written): void {
            $this->db()->table('analytics_daily_metrics')->where('day', $from->toDateString())->delete();

            foreach ($rows as [$metric, $dimension, $value]) {
                $this->db()->table('analytics_daily_metrics')->insert([
                    'day' => $from->toDateString(), 'metric' => $metric, 'dimension' => $dimension, 'value' => $value, 'computed_at' => now(),
                ]);
                $written[$metric.($dimension === '' ? '' : '|'.$dimension)] = $value;
            }
        });

        return $written;
    }

    /**
     * Delete raw ad events older than the retention window (days); the daily
     * rollups keep their totals.
     */
    public function prune(int $retentionDays): int
    {
        return $this->db()->table('ad_events')->where('created_at', '<', now()->subDays($retentionDays)->startOfDay())->delete();
    }
}
