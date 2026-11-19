<?php

namespace App\Domains\Reporting\Support;

use App\Domains\Reporting\Models\EtlRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cross-country admin reports (FR-CTY-10, ADR 2). Every query goes to the
 * reporting database only — never to the live country databases — and
 * money is grouped by currency, never summed across currencies.
 */
class CrossCountryReports
{
    private function db(): Connection
    {
        return DB::connection(ReportingEtl::CONNECTION);
    }

    /**
     * @param  array<int, string>  $countries
     */
    private function facts(string $table, array $countries, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->db()->table($table)->whereIn('country_code', $countries)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Per country × currency: orders, GMV, AOV, cancellations, buyers, returns, refunds.
     *
     * @param  array<int, string>  $countries
     * @return Collection<int, object>
     */
    public function sales(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->facts('fact_sales_daily', $countries, $from, $to)
            ->selectRaw('country_code, currency, sum(orders_count) as orders_count, sum(sub_orders_count) as sub_orders_count, sum(items_sold) as items_sold,
                sum(gmv) as gmv, sum(discount_total) as discount_total, sum(cancelled_orders) as cancelled_orders, sum(cancelled_value) as cancelled_value,
                sum(new_buyers) as new_buyers, sum(cod_orders) as cod_orders, sum(returns_requested) as returns_requested,
                sum(refunds_count) as refunds_count, sum(refunds_amount) as refunds_amount')
            ->groupBy('country_code', 'currency')->orderBy('country_code')->orderBy('currency')->get()
            ->each(function (object $row): void {
                $row->average_order_value = (int) $row->orders_count > 0 ? bcdiv((string) $row->gmv, (string) $row->orders_count, 4) : '0';
            });
    }

    /**
     * Per country: stores at the end of the range, new stores, stores with sales on the busiest day, active listings.
     *
     * @param  array<int, string>  $countries
     * @return Collection<string, object>
     */
    public function sellers(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $latest = $this->facts('fact_sellers_daily', $countries, $from, $to)->selectRaw('country_code, max(report_date) as report_date')->groupBy('country_code');

        return $this->db()->table('fact_sellers_daily as f')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.country_code', '=', 'f.country_code')->on('latest.report_date', '=', 'f.report_date'))
            ->select('f.country_code', 'f.active_sellers', 'f.active_listings')
            ->get()->keyBy('country_code')
            ->each(function (object $row) use ($countries, $from, $to): void {
                $totals = $this->facts('fact_sellers_daily', $countries, $from, $to)->where('country_code', $row->country_code)
                    ->selectRaw('sum(new_sellers) as new_sellers, max(selling_sellers) as peak_selling_sellers')->first();
                $row->new_sellers = (int) $totals->new_sellers;
                $row->peak_selling_sellers = (int) $totals->peak_selling_sellers;
            });
    }

    /**
     * @param  array<int, string>  $countries
     * @return Collection<int, object>
     */
    public function marketers(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->facts('fact_marketers_daily', $countries, $from, $to)
            ->selectRaw('country_code, currency, max(active_marketers) as active_marketers, sum(new_marketers) as new_marketers, sum(conversions_count) as conversions_count,
                sum(attributed_sales) as attributed_sales, sum(commissions_net) as commissions_net, sum(reversals_count) as reversals_count, sum(withdrawals_processed) as withdrawals_processed')
            ->groupBy('country_code', 'currency')->orderBy('country_code')->orderBy('currency')->get();
    }

    /**
     * @param  array<int, string>  $countries
     * @return Collection<int, object>
     */
    public function payouts(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->facts('fact_payouts_daily', $countries, $from, $to)
            ->selectRaw('country_code, currency, sum(generated_count) as generated_count, sum(generated_amount) as generated_amount, sum(completed_count) as completed_count, sum(completed_amount) as completed_amount')
            ->groupBy('country_code', 'currency')->orderBy('country_code')->orderBy('currency')->get();
    }

    /**
     * @param  array<int, string>  $countries
     * @return Collection<int, object>
     */
    public function revenue(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->facts('fact_revenue_daily', $countries, $from, $to)
            ->selectRaw('country_code, currency, account_code, sum(amount) as amount')
            ->groupBy('country_code', 'currency', 'account_code')->orderBy('country_code')->orderBy('account_code')->get();
    }

    /**
     * Daily GMV per currency across the countries (one series per currency).
     *
     * @param  array<int, string>  $countries
     * @return array<string, array<string, string>> currency => date => GMV
     */
    public function gmvTrend(array $countries, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $series = [];

        foreach ($this->facts('fact_sales_daily', $countries, $from, $to)->selectRaw('currency, report_date, sum(gmv) as gmv')->groupBy('currency', 'report_date')->orderBy('report_date')->get() as $row) {
            $series[$row->currency][substr((string) $row->report_date, 0, 10)] = (string) $row->gmv;
        }

        ksort($series);

        return $series;
    }

    /**
     * Daily sales rows for the CSV export.
     *
     * @param  array<int, string>  $countries
     * @return Collection<int, object>
     */
    public function dailySales(array $countries, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->facts('fact_sales_daily', $countries, $from, $to)->orderBy('report_date')->orderBy('country_code')->orderBy('currency')->get();
    }

    /**
     * The latest ETL run of each country.
     *
     * @param  array<int, string>  $countries
     * @return Collection<string, EtlRun>
     */
    public function lastRuns(array $countries): Collection
    {
        return EtlRun::query()->whereIn('id', EtlRun::query()->selectRaw('max(id)')->whereIn('country_code', $countries)->groupBy('country_code'))
            ->get()->keyBy('country_code');
    }
}
