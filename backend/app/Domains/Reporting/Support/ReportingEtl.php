<?php

namespace App\Domains\Reporting\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Reporting\Models\EtlRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nightly reporting ETL (FR-CTY-10, FR-ANL-2, ADR 2). For each country and
 * each of its local days, reads the country database and replaces that
 * day's rows in the reporting database:
 *
 * - `fact_sales_daily` (per currency): orders and GMV (cancelled orders
 *   excluded and counted apart), sub-orders, units, discounts, shipping,
 *   AOV, unique / first-time buyers, COD orders, return requests, completed
 *   refunds;
 * - `fact_sellers_daily`: active, new and selling stores, active listings;
 * - `fact_marketers_daily` (per currency): active and new marketers,
 *   conversions with attributed sales and net commission, reversals,
 *   processed withdrawals;
 * - `fact_payouts_daily` (per currency): vendor statements generated and
 *   completed;
 * - `fact_revenue_daily` (per currency × revenue account): net credits
 *   posted to the platform's revenue accounts in the ledger.
 *
 * A day is the country's local calendar day (timestamps are stored in UTC).
 * Reloading a day deletes and re-inserts its rows in one transaction, so
 * the job is idempotent and backfills are safe. Each country × run writes
 * an `etl_runs` row (running → succeeded | failed with the error).
 */
class ReportingEtl
{
    public const CONNECTION = 'reporting';

    public function __construct(private CountryContext $countryContext) {}

    /**
     * Load `$from`..`$to` (local dates, inclusive) for one country.
     */
    public function load(Country $country, CarbonImmutable $from, CarbonImmutable $to, string $trigger = 'schedule', ?int $userId = null, ?string $batch = null): EtlRun
    {
        $started = microtime(true);
        $run = EtlRun::query()->create([
            'batch' => $batch ?? (string) Str::uuid(),
            'country_code' => $country->code,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'status' => EtlRun::RUNNING,
            'trigger' => $trigger,
            'triggered_by_user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            $rows = $this->countryContext->runAs($country, function () use ($country, $from, $to): int {
                $rows = 0;

                for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                    $rows += $this->loadDay($country, $day);
                }

                return $rows;
            });

            $run->forceFill(['status' => EtlRun::SUCCEEDED, 'rows_written' => $rows])->save();
        } catch (Throwable $exception) {
            $run->forceFill(['status' => EtlRun::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            report($exception);
        } finally {
            $run->forceFill(['finished_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)])->save();
        }

        return $run;
    }

    /**
     * Yesterday in the country's time zone (the nightly default).
     */
    public function yesterday(Country $country): CarbonImmutable
    {
        return CarbonImmutable::now($country->timezone)->subDay()->startOfDay();
    }

    private function loadDay(Country $country, CarbonImmutable $day): int
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $day->toDateString().' 00:00:00', $country->timezone);
        $window = [$local->utc(), $local->addDay()->utc()];
        $date = $day->toDateString();
        $now = now();

        $facts = [
            'fact_sales_daily' => $this->sales($window),
            'fact_sellers_daily' => [$this->sellers($window)],
            'fact_marketers_daily' => $this->marketers($window, $country->currency),
            'fact_payouts_daily' => $this->payouts($window),
            'fact_revenue_daily' => $this->revenue($window),
        ];

        DB::connection(self::CONNECTION)->transaction(function () use ($facts, $country, $date, $now): void {
            foreach ($facts as $table => $rows) {
                DB::connection(self::CONNECTION)->table($table)->where('country_code', $country->code)->where('report_date', $date)->delete();

                if ($rows !== []) {
                    DB::connection(self::CONNECTION)->table($table)->insert(array_map(
                        fn (array $row): array => ['country_code' => $country->code, 'report_date' => $date] + $row + ['loaded_at' => $now],
                        $rows,
                    ));
                }
            }
        });

        return array_sum(array_map('count', $facts));
    }

    private function db(): Connection
    {
        return DB::connection($this->countryContext->connectionName());
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<int, array<string, mixed>>
     */
    private function sales(array $window): array
    {
        [$from, $to] = $window;
        $placed = fn () => $this->db()->table('orders')->where('placed_at', '>=', $from)->where('placed_at', '<', $to);
        $rows = [];

        $kept = $placed()->where('status', '!=', 'cancelled')
            ->selectRaw("currency, count(*) as orders_count, coalesce(sum(grand_total), 0) as gmv, coalesce(sum(discount_total), 0) as discount_total,
                coalesce(sum(shipping_total), 0) as shipping_total, count(distinct customer_user_id) as unique_buyers,
                sum(case when payment_method = 'cod' then 1 else 0 end) as cod_orders")
            ->groupBy('currency')->get()->keyBy('currency');
        $cancelled = $placed()->where('status', 'cancelled')
            ->selectRaw('currency, count(*) as orders_count, coalesce(sum(grand_total), 0) as value')->groupBy('currency')->get()->keyBy('currency');
        $subOrders = $this->db()->table('sub_orders')->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->where('orders.placed_at', '>=', $from)->where('orders.placed_at', '<', $to)->where('sub_orders.status', '!=', 'cancelled')
            ->selectRaw('orders.currency, count(*) as sub_orders_count')->groupBy('orders.currency')->pluck('sub_orders_count', 'currency');
        $units = $this->db()->table('order_items')->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->where('orders.placed_at', '>=', $from)->where('orders.placed_at', '<', $to)->where('sub_orders.status', '!=', 'cancelled')
            ->selectRaw('orders.currency, coalesce(sum(order_items.quantity), 0) as units')->groupBy('orders.currency')->pluck('units', 'currency');
        $newBuyers = $placed()->where('status', '!=', 'cancelled')
            ->whereNotExists(fn ($earlier) => $earlier->selectRaw('1')->from('orders as earlier')
                ->whereColumn('earlier.customer_user_id', 'orders.customer_user_id')->where('earlier.status', '!=', 'cancelled')->where('earlier.placed_at', '<', $from))
            ->selectRaw('currency, count(distinct customer_user_id) as buyers')->groupBy('currency')->pluck('buyers', 'currency');
        $returns = $this->db()->table('return_requests')->join('orders', 'orders.id', '=', 'return_requests.order_id')
            ->where('return_requests.created_at', '>=', $from)->where('return_requests.created_at', '<', $to)
            ->selectRaw('orders.currency, count(*) as returns')->groupBy('orders.currency')->pluck('returns', 'currency');
        $refunds = $this->db()->table('refunds')->where('status', 'completed')->where('processed_at', '>=', $from)->where('processed_at', '<', $to)
            ->selectRaw('currency, count(*) as refunds_count, coalesce(sum(amount), 0) as refunds_amount')->groupBy('currency')->get()->keyBy('currency');

        $currencies = collect([$kept->keys(), $cancelled->keys(), $returns->keys(), $refunds->keys()])->flatten()->unique()->sort()->values();

        foreach ($currencies as $currency) {
            $row = $kept->get($currency);
            $orders = (int) ($row->orders_count ?? 0);
            $gmv = (string) ($row->gmv ?? '0');

            $rows[] = [
                'currency' => $currency,
                'orders_count' => $orders,
                'sub_orders_count' => (int) ($subOrders[$currency] ?? 0),
                'items_sold' => (int) ($units[$currency] ?? 0),
                'gmv' => $gmv,
                'discount_total' => (string) ($row->discount_total ?? '0'),
                'shipping_total' => (string) ($row->shipping_total ?? '0'),
                'average_order_value' => $orders > 0 ? bcdiv($gmv, (string) $orders, 4) : '0',
                'cancelled_orders' => (int) ($cancelled->get($currency)->orders_count ?? 0),
                'cancelled_value' => (string) ($cancelled->get($currency)->value ?? '0'),
                'unique_buyers' => (int) ($row->unique_buyers ?? 0),
                'new_buyers' => (int) ($newBuyers[$currency] ?? 0),
                'cod_orders' => (int) ($row->cod_orders ?? 0),
                'returns_requested' => (int) ($returns[$currency] ?? 0),
                'refunds_count' => (int) ($refunds->get($currency)->refunds_count ?? 0),
                'refunds_amount' => (string) ($refunds->get($currency)->refunds_amount ?? '0'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<string, int>
     */
    private function sellers(array $window): array
    {
        [$from, $to] = $window;

        return [
            'active_sellers' => $this->db()->table('vendors')->where('status', 'active')->where('created_at', '<', $to)->count(),
            'new_sellers' => $this->db()->table('vendors')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'selling_sellers' => $this->db()->table('sub_orders')->join('orders', 'orders.id', '=', 'sub_orders.order_id')
                ->where('orders.placed_at', '>=', $from)->where('orders.placed_at', '<', $to)->where('sub_orders.status', '!=', 'cancelled')
                ->distinct()->count('sub_orders.vendor_id'),
            'active_listings' => $this->db()->table('listings')->where('status', 'active')->count(),
        ];
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<int, array<string, mixed>>
     */
    private function marketers(array $window, string $countryCurrency): array
    {
        [$from, $to] = $window;
        $conversions = $this->db()->table('marketer_conversions')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('currency, count(*) as conversions_count, coalesce(sum(basis), 0) as attributed_sales, coalesce(sum(net + bonus), 0) as commissions_net')
            ->groupBy('currency')->get()->keyBy('currency');
        $reversals = $this->db()->table('marketer_conversions')->where('reversed_at', '>=', $from)->where('reversed_at', '<', $to)
            ->selectRaw('currency, count(*) as reversals')->groupBy('currency')->pluck('reversals', 'currency');
        $withdrawals = $this->db()->table('marketer_withdrawals')->where('status', 'processed')->where('processed_at', '>=', $from)->where('processed_at', '<', $to)
            ->selectRaw('currency, coalesce(sum(amount), 0) as amount')->groupBy('currency')->pluck('amount', 'currency');
        $active = $this->db()->table('marketer_profiles')->where('global_status', 'active')->where('created_at', '<', $to)->count();
        $new = $this->db()->table('marketer_profiles')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        $currencies = collect([$conversions->keys(), $reversals->keys(), $withdrawals->keys(), [$countryCurrency]])->flatten()->unique()->sort()->values();

        return $currencies->map(fn (string $currency): array => [
            'currency' => $currency,
            // Head counts are not money: they sit on the country currency's row only.
            'active_marketers' => $currency === $countryCurrency ? $active : 0,
            'new_marketers' => $currency === $countryCurrency ? $new : 0,
            'conversions_count' => (int) ($conversions->get($currency)->conversions_count ?? 0),
            'attributed_sales' => (string) ($conversions->get($currency)->attributed_sales ?? '0'),
            'commissions_net' => (string) ($conversions->get($currency)->commissions_net ?? '0'),
            'reversals_count' => (int) ($reversals[$currency] ?? 0),
            'withdrawals_processed' => (string) ($withdrawals[$currency] ?? '0'),
        ])->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<int, array<string, mixed>>
     */
    private function payouts(array $window): array
    {
        [$from, $to] = $window;
        $generated = $this->db()->table('vendor_payouts')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('currency, count(*) as payouts, coalesce(sum(total), 0) as amount')->groupBy('currency')->get()->keyBy('currency');
        $completed = $this->db()->table('vendor_payouts')->where('status', 'completed')->where('completed_at', '>=', $from)->where('completed_at', '<', $to)
            ->selectRaw('currency, count(*) as payouts, coalesce(sum(total), 0) as amount')->groupBy('currency')->get()->keyBy('currency');

        return collect([$generated->keys(), $completed->keys()])->flatten()->unique()->sort()->values()->map(fn (string $currency): array => [
            'currency' => $currency,
            'generated_count' => (int) ($generated->get($currency)->payouts ?? 0),
            'generated_amount' => (string) ($generated->get($currency)->amount ?? '0'),
            'completed_count' => (int) ($completed->get($currency)->payouts ?? 0),
            'completed_amount' => (string) ($completed->get($currency)->amount ?? '0'),
        ])->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     * @return array<int, array<string, mixed>>
     */
    private function revenue(array $window): array
    {
        [$from, $to] = $window;

        return $this->db()->table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_accounts.owner_type', 'platform')->where('ledger_accounts.code', 'like', '%\_revenue')
            ->where('ledger_transactions.posted_at', '>=', $from)->where('ledger_transactions.posted_at', '<', $to)
            ->selectRaw("ledger_accounts.currency, ledger_accounts.code, coalesce(sum(case when ledger_entries.direction = 'credit' then ledger_entries.amount else -ledger_entries.amount end), 0) as amount")
            ->groupBy('ledger_accounts.currency', 'ledger_accounts.code')->orderBy('ledger_accounts.code')->get()
            ->map(fn (object $row): array => ['currency' => $row->currency, 'account_code' => $row->code, 'amount' => (string) $row->amount])->all();
    }
}
