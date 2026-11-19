<?php

namespace App\Domains\Migration\Support;

use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Migration\Steps\FilesStep;
use App\Domains\Migration\Steps\TravelStep;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\OrderStatusRollup;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The validation report of a migration (plan §8): every legacy row is
 * accounted for (migrated, archived, folded, dropped by rule or
 * quarantined with a reason); money per currency is equal; ledger groups
 * balance; wallet balances are reproduced; order statuses and their roll-up
 * match; identities were merged as counted; signatures and files verify;
 * no migrated row points at a missing target. `differences` must be empty
 * (or explained) before go-live.
 */
class Reconciler
{
    /**
     * @var array<int, string>
     */
    public array $differences = [];

    /**
     * Lifecycle statuses compared after the documented status maps (plan §8.3):
     * legacy table => [new table, legacy row → expected new status].
     *
     * @var array<string, array{0: string, 1: callable(array<string, mixed>): string}>
     */
    private const STATUS_MAPS = [
        'marketer_campaigns' => ['marketer_campaigns', [self::class, 'sameStatus']],
        'marketer_campaign_invitations' => ['campaign_invitations', [self::class, 'invitationStatus']],
        'marketer_campaign_conversions' => ['marketer_conversions', [self::class, 'conversionStatus']],
        'travel_bookings' => ['travel_bookings', [self::class, 'bookingStatus']],
        'classified_listings' => ['classified_ads', [self::class, 'sameStatus']],
        'gift_cards' => ['gift_cards', [self::class, 'sameStatus']],
        'support_tickets' => ['support_tickets', [self::class, 'sameStatus']],
        'payouts' => ['vendor_payouts', [self::class, 'sameStatus']],
        'refunds' => ['refunds', [self::class, 'sameStatus']],
    ];

    public function __construct(private LegacySource $source) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function sameStatus(array $row): string
    {
        return (string) ($row['status'] ?? '?');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function invitationStatus(array $row): string
    {
        return ($row['status'] ?? '?') === 'replaced' ? 'cancelled' : (string) ($row['status'] ?? '?');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function conversionStatus(array $row): string
    {
        return ($row['status'] ?? null) === 'approved' && ($row['wallet_released_at'] ?? null) !== null ? 'released' : (string) ($row['status'] ?? '?');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function bookingStatus(array $row): string
    {
        return TravelStep::BOOKING_STATUSES[$row['status'] ?? ''] ?? '?';
    }

    /**
     * Rows per legacy table: source vs accounted for.
     *
     * @return array<int, array{table: string, disposition: string, rule: string, source: int, migrated: int, quarantined: int, explained: int, difference: int}>
     */
    public function rowCounts(): array
    {
        $mapped = DB::table('migration_id_map')
            ->selectRaw("source_table, COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(source_id, '|', 1), ':', 1)) AS n")
            ->groupBy('source_table')->pluck('n', 'source_table');
        $quarantined = DB::table('migration_quarantine')->whereNull('resolved_at')->selectRaw('source_table, COUNT(*) AS n')->groupBy('source_table')->pluck('n', 'source_table');
        $rows = [];

        foreach (TableRules::all() as $table => $rule) {
            if (! $this->source->hasTable($table)) {
                continue;
            }

            $count = $this->source->count($table);
            $byRule = in_array($rule['disposition'], ['fold', 'drop'], true) ? $count : 0;
            $migratedCount = min($count, (int) ($mapped[$table] ?? 0));
            $quarantinedCount = (int) ($quarantined[$table] ?? 0);
            $difference = $count - $byRule - $migratedCount - $quarantinedCount;

            if ($difference !== 0) {
                $this->differences[] = "{$table}: {$difference} legacy row(s) neither migrated, archived nor quarantined";
            }

            $rows[] = ['table' => $table, 'disposition' => $rule['disposition'], 'rule' => $rule['rule'], 'source' => $count, 'migrated' => $migratedCount, 'quarantined' => $quarantinedCount, 'explained' => $byRule, 'difference' => $difference];
        }

        return $rows;
    }

    /**
     * Target rows written per country and table (from the id map).
     *
     * @return array<string, array<string, int>> target table => [db => rows]
     */
    public function countryCounts(): array
    {
        $matrix = [];

        foreach (DB::table('migration_id_map')->selectRaw('target_table, target_db, COUNT(*) AS n')->groupBy('target_table', 'target_db')->get() as $row) {
            $matrix[$row->target_table][$row->target_db] = (int) $row->n;
        }

        ksort($matrix);

        return $matrix;
    }

    /**
     * Every id-map entry points at an existing target row.
     *
     * @return array<string, int> "db.table" => missing rows
     */
    public function orphans(): array
    {
        $missing = [];

        foreach (DB::table('migration_id_map')->select('target_db', 'target_table')->distinct()->get() as $pair) {
            $ids = DB::table('migration_id_map')->where('target_db', $pair->target_db)->where('target_table', $pair->target_table)->pluck('target_id')->unique();
            $found = 0;

            foreach ($ids->chunk(1000) as $chunk) {
                $found += MigrationContext::db($pair->target_db)->table($pair->target_table)->whereIn('id', $chunk->all())->count();
            }

            if ($found !== $ids->count()) {
                $missing[$pair->target_db.'.'.$pair->target_table] = $ids->count() - $found;
                $this->differences[] = "{$pair->target_db}.{$pair->target_table}: ".($ids->count() - $found).' mapped row(s) missing';
            }
        }

        foreach (MigrationContext::allCountries() as $code) {
            $db = MigrationContext::db($code);
            $checks = [
                'listings → vendors' => $db->table('listings')->whereNotNull('legacy_id')->whereNotIn('vendor_id', $db->table('vendors')->select('id'))->count(),
                'listings → product_variants' => $db->table('listings')->whereNotNull('legacy_id')->whereNotIn('product_variant_id', $db->table('product_variants')->select('id'))->count(),
                'product_variants → products' => $db->table('product_variants')->whereNotNull('legacy_id')->whereNotIn('product_id', $db->table('products')->select('id'))->count(),
                'sub_orders → orders' => $db->table('sub_orders')->whereNotNull('legacy_id')->whereNotIn('order_id', $db->table('orders')->select('id'))->count(),
                'order_items → sub_orders' => $db->table('order_items')->whereNotNull('legacy_id')->whereNotIn('sub_order_id', $db->table('sub_orders')->select('id'))->count(),
                'vendors → users' => $db->table('vendors')->whereNotNull('legacy_id')->whereNotIn('owner_user_id', DB::table('users')->pluck('id')->all())->count(),
            ];

            foreach ($checks as $name => $count) {
                if ($count > 0) {
                    $missing["{$code}: {$name}"] = $count;
                    $this->differences[] = "{$code}: {$count} orphan {$name}";
                }
            }
        }

        return $missing;
    }

    /**
     * Money per currency: legacy sums of migrated rows vs the new rows, and
     * archived money tables vs their archived payloads.
     *
     * @return array<int, array{check: string, currency: string, legacy: string, target: string, equal: bool}>
     */
    public function money(): array
    {
        $out = [];
        $compare = function (string $check, array $legacy, array $target) use (&$out): void {
            foreach (array_unique([...array_keys($legacy), ...array_keys($target)]) as $currency) {
                $a = (string) ($legacy[$currency] ?? BigDecimal::zero()->toScale(4));
                $b = (string) ($target[$currency] ?? BigDecimal::zero()->toScale(4));
                $equal = BigDecimal::of($a)->isEqualTo(BigDecimal::of($b));
                $out[] = ['check' => $check, 'currency' => $currency, 'legacy' => $a, 'target' => $b, 'equal' => $equal];

                if (! $equal) {
                    $this->differences[] = "{$check} {$currency}: legacy {$a} ≠ new {$b}";
                }
            }
        };

        $compare('Σ order totals', $this->legacySum('orders', 'total', 'currency'), $this->targetSum('orders', 'orders', 'grand_total', 'currency'));
        $compare('Σ order item line totals', $this->legacySum('order_items', 'line_total', null, 'orders'), $this->targetSum('order_items', 'order_items', 'line_total', null));
        $compare('Σ listing prices', $this->legacySum('vendor_listings', 'price', 'currency'), $this->targetSum('vendor_listings', 'listings', 'COALESCE(sale_price, price)', null));
        $compare('Σ wallet balances', $this->legacySum('wallets', 'balance', 'currency'), $this->targetSum('wallets', 'ledger_accounts', 'balance', 'currency'));

        // Tables mapped after WP11 (payments, refunds, payouts, campaigns, conversions, travel, classifieds, gift cards).
        $compare('Σ payments', $this->mappedLegacySum('payment_transactions', 'payments', fn (array $row): string => (string) $row['amount'], 'currency'), $this->targetSum('payment_transactions', 'payments', 'amount', 'currency'));
        $compare('Σ payment refunds', $this->mappedLegacySum('payment_transactions', 'payment_refunds', fn (array $row): string => (string) $row['amount'], 'currency'), $this->targetSum('payment_transactions', 'payment_refunds', 'amount', null));
        $compare('Σ refunds (net)', $this->mappedLegacySum('refunds', 'refunds', fn (array $row): string => (string) ($row['net_refund'] ?? $row['amount']), 'currency'), $this->targetSum('refunds', 'refunds', 'amount', 'currency'));
        $compare('Σ payouts (net)', $this->mappedLegacySum('payouts', 'vendor_payouts', fn (array $row): string => (string) $row['net_amount'], 'currency'), $this->targetSum('payouts', 'vendor_payouts', 'total', 'currency'));
        $compare('Σ campaign budget spent', $this->mappedLegacySum('marketer_campaigns', 'marketer_campaigns', fn (array $row): string => (string) $row['commission_budget_spent'], 'currency'), $this->targetSum('marketer_campaigns', 'marketer_campaigns', 'budget_spent', 'currency'));
        $compare('Σ campaign influencer fixed reserved', $this->mappedLegacySum('marketer_campaigns', 'marketer_campaigns', fn (array $row): string => (string) $row['influencer_fixed_reserved'], 'currency'), $this->targetSum('marketer_campaigns', 'marketer_campaigns', 'influencer_fixed_reserved', 'currency'));
        $compare('Σ conversion gross', $this->mappedLegacySum('marketer_campaign_conversions', 'marketer_conversions', fn (array $row): string => (string) ($row['gross_commission_amount'] ?? $row['commission_amount']), 'currency'), $this->targetSum('marketer_campaign_conversions', 'marketer_conversions', 'gross', 'currency'));
        $compare('Σ travel booking totals', $this->mappedLegacySum('travel_bookings', 'travel_bookings', fn (array $row): string => (string) $row['total_price'], null, 'travel_packages', 'travel_package_id'), $this->targetSum('travel_bookings', 'travel_bookings', 'total', 'currency'));
        $compare('Σ classified ad prices', $this->mappedLegacySum('classified_listings', 'classified_ads', fn (array $row): string => (string) $row['price'], 'currency'), $this->targetSum('classified_listings', 'classified_ads', 'price', 'currency'));
        $compare('Σ gift card balances', $this->mappedLegacySum('gift_cards', 'gift_cards', fn (array $row): string => (string) $row['remaining_balance'], 'currency_code'), $this->targetSum('gift_cards', 'gift_cards', 'balance', 'currency'));

        foreach (TableRules::all() as $table => $rule) {
            if ($rule['disposition'] !== 'archive' || ! $this->source->hasTable($table)) {
                continue;
            }

            $moneyColumns = array_values(array_filter($this->source->columns($table), fn (string $column): bool => in_array($column, ['amount', 'total', 'balance', 'price', 'net_refund', 'commission_amount'], true)));

            if ($moneyColumns === [] || $this->source->count($table) === 0) {
                continue;
            }

            $currencyColumn = $this->source->hasColumn($table, 'currency') ? 'currency' : ($this->source->hasColumn($table, 'currency_code') ? 'currency_code' : null);
            $archived = $this->archivedSum($table, $moneyColumns[0], $currencyColumn);
            $compare("Σ {$table}.{$moneyColumns[0]} (archived)", $this->legacySum($table, $moneyColumns[0], $currencyColumn, null, true), $archived);
        }

        return $out;
    }

    /**
     * Every migrated ledger transaction balances; every migrated wallet
     * account's balance equals the legacy balance and its entries.
     *
     * @return array{transactions: int, unbalanced: int, wallets: int, wallet_mismatches: int}
     */
    public function ledger(): array
    {
        $transactions = 0;
        $unbalanced = 0;

        foreach (DB::table('migration_id_map')->where('target_table', 'ledger_transactions')->get() as $entry) {
            $transactions++;
            $sums = MigrationContext::db($entry->target_db)->table('ledger_entries')->where('ledger_transaction_id', $entry->target_id)
                ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) AS debits, SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) AS credits")->first();

            if (bccomp((string) $sums->debits, (string) $sums->credits, 4) !== 0) {
                $unbalanced++;
                $this->differences[] = "ledger transaction {$entry->target_db}#{$entry->target_id}: debits {$sums->debits} ≠ credits {$sums->credits}";
            }
        }

        $wallets = 0;
        $mismatches = 0;

        foreach (DB::table('migration_id_map')->whereIn('source_table', ['wallets', 'customer_wallets'])->where('target_table', 'ledger_accounts')->get() as $entry) {
            $baseId = explode(':', $entry->source_id)[0];
            $legacy = $this->source->find($entry->source_table, $baseId);
            $account = MigrationContext::db($entry->target_db)->table('ledger_accounts')->where('id', $entry->target_id)->first();

            if ($legacy === null || $account === null) {
                continue;
            }

            $wallets++;
            $expected = str_ends_with($entry->source_id, ':pending') ? (string) ($legacy['pending_balance'] ?? '0') : (string) $legacy['balance'];
            $debits = (string) MigrationContext::db($entry->target_db)->table('ledger_entries')->where('ledger_account_id', $account->id)->where('direction', 'debit')->sum('amount');
            $credits = (string) MigrationContext::db($entry->target_db)->table('ledger_entries')->where('ledger_account_id', $account->id)->where('direction', 'credit')->sum('amount');
            $projected = $account->normal_side === 'debit' ? bcsub($debits, $credits, 4) : bcsub($credits, $debits, 4);

            if (bccomp((string) $account->balance, $expected, 4) !== 0 || bccomp($projected, $expected, 4) !== 0) {
                $mismatches++;
                $this->differences[] = "wallet {$baseId}: legacy {$expected}, account {$account->balance}, entries {$projected}";
            }
        }

        return ['transactions' => $transactions, 'unbalanced' => $unbalanced, 'wallets' => $wallets, 'wallet_mismatches' => $mismatches];
    }

    /**
     * Status distributions of migrated rows (legacy vs new) and the order roll-up.
     *
     * @return array<string, array<string, array{legacy: int, target: int}>>
     */
    public function statuses(): array
    {
        $out = [];

        foreach (['orders' => ['orders', 'status'], 'sub_orders' => ['sub_orders', 'status'], 'vendor_listings' => ['listings', 'status']] as $legacyTable => [$targetTable, $column]) {
            $legacy = [];
            $target = [];

            foreach (DB::table('migration_id_map')->where('source_table', $legacyTable)->where('target_table', $targetTable)->get() as $entry) {
                $legacyRow = $this->source->find($legacyTable, $entry->source_id);
                $legacyStatus = $legacyTable === 'vendor_listings' && ($legacyRow['deleted_at'] ?? null) !== null ? 'archived' : (string) ($legacyRow[$column] ?? '?');
                $legacy[$legacyStatus] = ($legacy[$legacyStatus] ?? 0) + 1;
                $targetStatus = (string) MigrationContext::db($entry->target_db)->table($targetTable)->where('id', $entry->target_id)->value($column);
                $target[$targetStatus] = ($target[$targetStatus] ?? 0) + 1;
            }

            foreach (array_unique([...array_keys($legacy), ...array_keys($target)]) as $status) {
                $out[$legacyTable][$status] = ['legacy' => $legacy[$status] ?? 0, 'target' => $target[$status] ?? 0];

                if (($legacy[$status] ?? 0) !== ($target[$status] ?? 0)) {
                    $this->differences[] = "{$legacyTable} status {$status}: legacy ".($legacy[$status] ?? 0).' ≠ new '.($target[$status] ?? 0);
                }
            }
        }

        foreach (self::STATUS_MAPS as $legacyTable => [$targetTable, $map]) {
            $legacy = [];
            $target = [];

            foreach (DB::table('migration_id_map')->where('source_table', $legacyTable)->where('target_table', $targetTable)->where('origin', 'inserted')->get() as $entry) {
                $legacyRow = $this->source->find($legacyTable, $entry->source_id) ?? [];
                $legacyStatus = $map($legacyRow);
                $legacy[$legacyStatus] = ($legacy[$legacyStatus] ?? 0) + 1;
                $targetStatus = (string) MigrationContext::db($entry->target_db)->table($targetTable)->where('id', $entry->target_id)->value('status');
                $target[$targetStatus] = ($target[$targetStatus] ?? 0) + 1;
            }

            foreach (array_unique([...array_keys($legacy), ...array_keys($target)]) as $status) {
                $out[$legacyTable][$status] = ['legacy' => $legacy[$status] ?? 0, 'target' => $target[$status] ?? 0];

                if (($legacy[$status] ?? 0) !== ($target[$status] ?? 0)) {
                    $this->differences[] = "{$legacyTable} status {$status}: legacy (mapped) ".($legacy[$status] ?? 0).' ≠ new '.($target[$status] ?? 0);
                }
            }
        }

        $rollup = app(OrderStatusRollup::class);

        foreach (DB::table('migration_id_map')->where('source_table', 'orders')->where('target_table', 'orders')->get() as $entry) {
            $db = MigrationContext::db($entry->target_db);
            $status = (string) $db->table('orders')->where('id', $entry->target_id)->value('status');
            $subs = $db->table('sub_orders')->where('order_id', $entry->target_id)->pluck('status')->map(fn (string $value): SubOrderStatus => SubOrderStatus::from($value));

            if ($status !== OrderStatus::Disputed->value && $subs->isNotEmpty() && $rollup->derive($subs)->value !== $status) {
                $this->differences[] = "order {$entry->target_db}#{$entry->target_id}: status {$status} ≠ roll-up ".$rollup->derive($subs)->value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function identity(): array
    {
        $tables = ['customers', 'admins', 'vendors', 'vendor_admins', 'marketers', 'marketer_admins', 'delivery_agents', 'travel_agencies', 'travel_agency_members', 'shipping_company_supervisors'];
        $logins = array_sum(array_map(fn (string $table): int => $this->source->count($table), $tables));
        $users = DB::table('migration_id_map')->whereIn('source_table', $tables)->where('target_table', 'users');

        return [
            'legacy accounts' => $logins,
            'users created' => (clone $users)->where('origin', 'inserted')->distinct()->count('target_id'),
            'accounts merged into one person' => (clone $users)->where('origin', 'merged')->count(),
            'people with several legacy accounts' => (clone $users)->select('target_id')->groupBy('target_id')->havingRaw('COUNT(*) > 1')->get()->count(),
            'memberships' => DB::table('migration_id_map')->where('target_table', 'role_memberships')->distinct()->count('target_id'),
            'requires_email (phone-only)' => DB::table('users')->whereNotNull('legacy_id')->where('requires_email', true)->count(),
            'identity quarantined' => DB::table('migration_quarantine')->whereNull('resolved_at')->whereIn('source_table', $tables)->count(),
        ];
    }

    /**
     * Migrated signatures: the frozen copy matches its SHA-256 (ContractSigner::verify).
     *
     * @return array{signatures: int, verified: int}
     */
    public function signatures(): array
    {
        $signatures = 0;
        $verified = 0;

        foreach (DB::table('migration_id_map')->where('target_table', 'contract_signatures')->get() as $entry) {
            $signature = MigrationContext::db($entry->target_db)->table('contract_signatures')->where('id', $entry->target_id)->first();
            $signatures++;
            $bytes = $signature === null ? null : Storage::disk(ContractRenderer::DISK)->get($signature->frozen_path);

            if ($bytes !== null && hash_equals($signature->sha256, hash('sha256', $bytes))) {
                $verified++;
            } else {
                $this->differences[] = "signature {$entry->source_id}: frozen copy does not verify";
            }
        }

        return ['signatures' => $signatures, 'verified' => $verified];
    }

    /**
     * @return array{referenced: int, present: int}
     */
    public function files(): array
    {
        $referenced = 0;
        $present = 0;
        $seen = [];

        foreach (MigrationContext::allCountries() as $code) {
            foreach (FilesStep::REFERENCES as $table => $column) {
                foreach (MigrationContext::db($code)->table($table)->where($column, 'like', 'legacy/%')->distinct()->pluck($column) as $path) {
                    if (isset($seen[$path])) {
                        continue;
                    }

                    $seen[$path] = true;
                    $referenced++;
                    $copy = Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : null;
                    $original = (new LegacyFiles)->read('public', substr($path, 7));

                    if ($copy !== null && $original !== null && hash('sha256', $copy) === hash('sha256', $original)) {
                        $present++;
                    }
                }
            }
        }

        return ['referenced' => $referenced, 'present' => $present];
    }

    /**
     * @return array<int, array{reason: string, table: string, count: int, detail: string}>
     */
    public function quarantine(): array
    {
        return DB::table('migration_quarantine')->whereNull('resolved_at')
            ->selectRaw('reason, source_table, COUNT(*) AS n, MIN(detail) AS detail')->groupBy('reason', 'source_table')->orderBy('source_table')->get()
            ->map(fn (object $row): array => ['reason' => $row->reason, 'table' => $row->source_table, 'count' => (int) $row->n, 'detail' => self::mask((string) $row->detail)])->all();
    }

    /**
     * Masks emails, phone numbers and IBANs (plan §2.6).
     */
    public static function mask(string $text): string
    {
        $text = preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', fn (array $match): string => mb_substr($match[0], 0, 2).'***@***', $text) ?? $text;
        $text = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '[IBAN]', $text) ?? $text;

        return preg_replace_callback('/(?<![\w-])\+?\d[\d\s().-]{7,}\d(?![\w-])/', fn (array $match): string => str_repeat('•', max(0, strlen($match[0]) - 3)).substr($match[0], -3), $text) ?? $text;
    }

    /**
     * Σ of a legacy money column per currency over rows that were migrated
     * (or, for archived tables, every row that was archived).
     *
     * @return array<string, BigDecimal>
     */
    private function legacySum(string $table, string $column, ?string $currencyColumn, ?string $currencyFrom = null, bool $archived = false): array
    {
        if (! $this->source->hasTable($table)) {
            return [];
        }

        $mappedIds = DB::table('migration_id_map')->where('source_table', $table)
            ->when($archived, fn ($query) => $query->where('target_table', 'legacy_records'), fn ($query) => $query->where('target_table', '!=', 'legacy_records')->where('target_table', '!=', 'role_memberships')->where('target_table', '!=', 'users'))
            ->pluck('source_id')->map(fn (string $id): string => explode(':', explode('|', $id)[0])[0])->unique()->flip();
        $sums = [];

        foreach ($this->source->rows($table) as $row) {
            if (! isset($mappedIds[(string) ($row['id'] ?? '')])) {
                continue;
            }

            $currency = $currencyColumn !== null ? strtoupper((string) $row[$currencyColumn]) : strtoupper((string) ($this->source->find($currencyFrom ?? 'orders', (string) ($row['order_id'] ?? ''))['currency'] ?? 'N/A'));
            $sums[$currency] = ($sums[$currency] ?? BigDecimal::zero())->plus(BigDecimal::of((string) ($row[$column] ?? '0')));
        }

        return $sums;
    }

    /**
     * Σ of a legacy money value per currency over the rows a mapping
     * inserted into one new table (links and folded rows left out).
     *
     * @param  callable(array<string, mixed>): string  $value
     * @return array<string, BigDecimal>
     */
    private function mappedLegacySum(string $table, string $targetTable, callable $value, ?string $currencyColumn, ?string $currencyTable = null, ?string $currencyVia = null): array
    {
        if (! $this->source->hasTable($table)) {
            return [];
        }

        $ids = DB::table('migration_id_map')->where('source_table', $table)->where('target_table', $targetTable)->where('origin', 'inserted')
            ->pluck('source_id')->reject(fn (string $id): bool => str_contains($id, ':') || str_contains($id, '|'))->flip();
        $sums = [];

        foreach ($this->source->rows($table) as $row) {
            if (! isset($ids[(string) ($row['id'] ?? '')])) {
                continue;
            }

            $currency = $currencyColumn !== null
                ? strtoupper((string) $row[$currencyColumn])
                : strtoupper((string) ($this->source->find((string) $currencyTable, (string) ($row[$currencyVia] ?? ''))['currency'] ?? 'N/A'));
            $sums[$currency] = ($sums[$currency] ?? BigDecimal::zero())->plus(BigDecimal::of($value($row) ?: '0'));
        }

        return $sums;
    }

    /**
     * Σ of a new money column per currency over the rows migrated from a legacy table.
     *
     * @return array<string, BigDecimal>
     */
    private function targetSum(string $sourceTable, string $targetTable, string $expression, ?string $currencyColumn): array
    {
        $sums = [];

        foreach (DB::table('migration_id_map')->where('source_table', $sourceTable)->where('target_table', $targetTable)->get()->groupBy('target_db') as $db => $entries) {
            $currencies = $currencyColumn === null ? null : $currencyColumn;
            $rows = MigrationContext::db($db)->table($targetTable)->whereIn('id', $entries->pluck('target_id')->unique()->all())
                ->selectRaw(($currencies ?? "'".config("nawy.countries.{$db}.currency")."'").' AS currency, SUM('.$expression.') AS total')->groupBy('currency')->get();

            foreach ($rows as $row) {
                $sums[$row->currency] = ($sums[$row->currency] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $row->total));
            }
        }

        return $sums;
    }

    /**
     * @return array<string, BigDecimal>
     */
    private function archivedSum(string $table, string $column, ?string $currencyColumn): array
    {
        $sums = [];

        foreach (DB::table('migration_id_map')->where('source_table', $table)->where('target_table', 'legacy_records')->get()->groupBy('target_db') as $db => $entries) {
            foreach (MigrationContext::db($db)->table('legacy_records')->whereIn('id', $entries->pluck('target_id')->all())->pluck('payload') as $payload) {
                $row = (array) json_decode((string) $payload, true);
                $currency = $currencyColumn !== null ? strtoupper((string) ($row[$currencyColumn] ?? '')) : 'N/A';
                $sums[$currency] = ($sums[$currency] ?? BigDecimal::zero())->plus(BigDecimal::of((string) ($row[$column] ?? '0')));
            }
        }

        return $sums;
    }
}
