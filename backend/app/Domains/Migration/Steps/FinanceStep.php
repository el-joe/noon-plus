<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Collection;

/**
 * Wallets and the ledger (MG5, plan §4.1). Exact, append-only:
 * - each legacy wallet (owner × currency, in the currency's country) becomes
 *   the owner's wallet ledger account; every wallet transaction becomes one
 *   balanced ledger transaction (wallet leg + `wallet_clearing` leg) whose
 *   wallet entry keeps the legacy `balance_after`. The chain is checked
 *   first — each balance_after = previous ± amount, and the last one = the
 *   wallet balance — and a wallet that does not reproduce is quarantined
 *   with all its transactions (nothing partial is written). A balance or
 *   pending balance without history is posted as one opening transaction.
 * - legacy ledger_entries move per transaction group into accounts of
 *   bucket `legacy` (history only, so live wallet balances are not counted
 *   twice); a group whose debits ≠ credits, mixes currencies or uses an
 *   account type with no new equivalent is quarantined whole.
 */
class FinanceStep extends Step
{
    /**
     * Legacy ledger account types → new chart of accounts (gateway_fee and
     * carrier_payable have none: their groups are quarantined).
     *
     * @var array<string, LedgerAccountCode>
     */
    private const ACCOUNTS = [
        'customer_payment' => LedgerAccountCode::GatewayClearing,
        'platform_revenue' => LedgerAccountCode::FeeRevenue,
        'platform_commission' => LedgerAccountCode::CommissionRevenue,
        'seller_payable' => LedgerAccountCode::VendorPayable,
        'tax_payable' => LedgerAccountCode::VatPayable,
        'refund_liability' => LedgerAccountCode::OrderLiability,
        'shipping_revenue' => LedgerAccountCode::ShippingRevenue,
        'cod_clearing' => LedgerAccountCode::CodReceivable,
        'marketer_commission_payable' => LedgerAccountCode::MarketerPayable,
        'marketer_payable' => LedgerAccountCode::MarketerPayable,
        'warranty_revenue' => LedgerAccountCode::WarrantyRevenue,
        'coupon_expense' => LedgerAccountCode::PromoExpense,
        'shipping_subsidy_expense' => LedgerAccountCode::ShippingSubsidyExpense,
        'wallet_liability' => LedgerAccountCode::CustomerWallet,
    ];

    public function name(): string
    {
        return 'finance';
    }

    protected function order(): array
    {
        return ['wallets', 'customer_wallets', 'wallet_transactions', 'ledger_entries'];
    }

    /**
     * Vendor payout statements (plan §6 Finance): one vendor payout in the
     * payout currency's country, numbered as before, with the vendor's
     * payout schedule; the total is the legacy net amount (copied, not
     * recomputed). The gross / commission / fee / refund breakdown, bank
     * account and receipt stay readable in `legacy_records` next to it.
     * A currency whose country the vendor was not migrated to is
     * quarantined; payout lines (`payout_items`) stay archived as history.
     */
    protected function migratePayouts(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $code = $this->countries->codeForCurrency((string) $row['currency']);

            if ($code === null) {
                $this->quarantine->put($table, $id, 'currency_mismatch', "payout in {$row['currency']}: no launched country uses it", null, $row);

                continue;
            }

            if (! $this->context->allows($code)) {
                $this->context->count($table, 'skipped_other_country');

                continue;
            }

            $vendorId = $this->ids->find('vendors', (string) $row['vendor_id'], $code, 'vendors');

            if ($vendorId === null) {
                $this->quarantine->put($table, $id, 'parent_unmigrated', "vendor not migrated to {$code}", $code, $row);

                continue;
            }

            $approvedBy = $this->userOf('admins', $row['approved_by_admin_id'] === null ? null : (string) $row['approved_by_admin_id']);

            $this->writer->upsert($table, $id, $code, 'vendor_payouts', [
                'number' => $this->writer->unique($code, 'vendor_payouts', 'number', (string) $row['payout_number'], $this->ids->find($table, $id, $code, 'vendor_payouts'), [], 40),
                'vendor_id' => $vendorId,
                'currency' => strtoupper((string) $row['currency']),
                'status' => $row['status'],
                'schedule' => (string) MigrationContext::db($code)->table('vendors')->where('id', $vendorId)->value('payout_schedule'),
                'period_start' => $row['period_start'],
                'period_end' => $row['period_end'],
                'total' => $row['net_amount'],
                'approved_by_user_id' => $approvedBy,
                'hold_reason' => $row['status'] === 'on_hold' && $row['failed_reason'] !== null ? mb_substr((string) $row['failed_reason'], 0, 255) : null,
                'failure_reason' => $row['status'] === 'failed' && $row['failed_reason'] !== null ? mb_substr((string) $row['failed_reason'], 0, 255) : null,
                'bank_reference' => $row['gateway_reference'] === null ? null : mb_substr((string) $row['gateway_reference'], 0, 100),
                'approved_at' => in_array($row['status'], ['approved', 'processing', 'completed'], true) && $approvedBy !== null ? ($row['updated_at'] ?? $row['created_at']) : null,
                'completed_at' => $row['status'] === 'completed' ? ($row['processed_at'] ?? $row['updated_at']) : null,
                ...$this->timestamps($row),
            ]);
            $this->keepLegacyRow($table, $id, $row, $code);
            $this->context->sum($table, $row['currency'], (string) $row['net_amount']);
        }
    }

    protected function migrateWallets(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $this->wallet($table, $row, (string) $row['owner_type'], (string) $row['owner_id'], (string) $row['currency'], (string) $row['balance'], (string) ($row['pending_balance'] ?? '0'), (bool) $row['is_frozen'],
                $this->source->connection()->table('wallet_transactions')->where('wallet_id', $row['id'])->orderBy('created_at')->orderBy('id')->get()->map(fn (object $tx): array => (array) $tx));
        }
    }

    /**
     * The older per-customer wallet table (no transaction history of its own).
     */
    protected function migrateCustomerWallets(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $this->wallet($table, $row, 'customer', (string) $row['customer_id'], (string) $row['currency_code'], (string) $row['balance'], '0', false, collect());
        }
    }

    /**
     * Transactions move with their wallet; this only counts them.
     */
    protected function migrateWalletTransactions(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            if (! $this->quarantine->has($table, (string) $row['id'])) {
                $this->context->count($table, 'moved_with_wallet');
            }
        }
    }

    protected function migrateLedgerEntries(string $table): void
    {
        $groups = $this->rows($table)->groupBy('transaction_group_id');

        foreach ($groups as $groupId => $entries) {
            $this->ledgerGroup($table, (string) $groupId, $entries->values());
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, array<string, mixed>>  $transactions
     */
    private function wallet(string $table, array $row, string $ownerType, string $ownerId, string $currency, string $balance, string $pending, bool $frozen, Collection $transactions): void
    {
        $id = (string) $row['id'];
        $currency = strtoupper($currency);
        $code = $this->countries->codeForCurrency($currency);
        $reject = function (string $reason, string $detail) use ($table, $id, $code, $row, $transactions): void {
            $this->quarantine->put($table, $id, $reason, $detail, $code, $row);

            foreach ($transactions as $transaction) {
                $this->quarantine->put('wallet_transactions', (string) $transaction['id'], 'parent_quarantined', "wallet {$id}: {$reason}", $code, $transaction);
            }
        };

        if ($code === null) {
            $reject('country_unresolved', "wallet currency {$currency} is not a launched country's currency");

            return;
        }

        if (! $this->context->allows($code)) {
            $this->context->count($table, 'skipped_other_country');

            return;
        }

        $owner = $this->owner($ownerType, $ownerId, $code);

        if ($owner === null) {
            $reject('owner_unresolved', "{$ownerType} {$ownerId} not migrated to {$code} (or no wallet in the new system)");

            return;
        }

        $chain = $this->checkChain($transactions, $balance);

        if ($chain !== null) {
            $reject('balance_mismatch', $chain);

            return;
        }

        [$accountCode, $ownerKind, $ownerKey] = $owner;
        $bucket = $frozen && $accountCode === LedgerAccountCode::CustomerWallet ? 'frozen' : 'spendable';
        $accountId = $this->account($table, $id, $code, $accountCode, $ownerKind, $ownerKey, $bucket, $currency, $balance);
        $running = '0';

        foreach ($transactions as $transaction) {
            $direction = $transaction['direction'] === 'credit' ? 'credit' : 'debit';
            $running = (string) $transaction['balance_after'];
            $this->post($code, 'wallet_transactions', (string) $transaction['id'], 'legacy_wallet_'.mb_substr((string) $transaction['type'], 0, 25), $currency, (string) $transaction['amount'], $accountId, $direction, $running, $transaction['description'] ?? $transaction['note'] ?? null, $transaction['created_at']);
            $this->context->sum('wallet_transactions', $currency, $direction === 'credit' ? $transaction['amount'] : '-'.$transaction['amount']);
        }

        if ($transactions->isEmpty() && bccomp($balance, '0', 4) !== 0) {
            $this->post($code, $table, $id.':opening', 'legacy_opening_balance', $currency, ltrim($balance, '-'), $accountId, bccomp($balance, '0', 4) > 0 ? 'credit' : 'debit', $balance, 'Opening balance from the old platform', $row['created_at'] ?? null);
        }

        if (bccomp($pending, '0', 4) !== 0) {
            $pendingAccount = $this->account($table, $id.':pending', $code, $accountCode, $ownerKind, $ownerKey, 'pending', $currency, $pending);
            $this->post($code, $table, $id.':pending_opening', 'legacy_opening_pending', $currency, ltrim($pending, '-'), $pendingAccount, bccomp($pending, '0', 4) > 0 ? 'credit' : 'debit', $pending, 'Pending balance from the old platform', $row['created_at'] ?? null);
        }

        $this->context->sum($table, $currency, $balance);
    }

    /**
     * Null when every balance_after follows from the previous one and the
     * last equals the wallet balance; else what breaks.
     *
     * @param  Collection<int, array<string, mixed>>  $transactions
     */
    private function checkChain(Collection $transactions, string $balance): ?string
    {
        $running = '0';

        foreach ($transactions as $transaction) {
            $amount = (string) $transaction['amount'];
            $running = $transaction['direction'] === 'credit' ? bcadd($running, $amount, 4) : bcsub($running, $amount, 4);

            if (bccomp($running, (string) $transaction['balance_after'], 4) !== 0) {
                return "transaction {$transaction['id']}: balance_after {$transaction['balance_after']} ≠ projected {$running}";
            }
        }

        if ($transactions->isNotEmpty() && bccomp($running, $balance, 4) !== 0) {
            return "projected balance {$running} ≠ wallet balance {$balance}";
        }

        return null;
    }

    /**
     * @return array{0: LedgerAccountCode, 1: string, 2: int}|null
     */
    private function owner(string $ownerType, string $ownerId, string $code): ?array
    {
        return match ($ownerType) {
            'customer' => ($id = $this->ids->find('customers', $ownerId, MigrationContext::CENTRAL, 'users')) === null ? null : [LedgerAccountCode::CustomerWallet, LedgerAccount::OWNER_CUSTOMER, $id],
            'vendor' => ($id = $this->ids->find('vendors', $ownerId, $code, 'vendors')) === null ? null : [LedgerAccountCode::VendorPayable, LedgerAccount::OWNER_VENDOR, $id],
            'marketer' => ($id = $this->ids->find('marketers', $ownerId, $code, 'marketer_profiles')) === null ? null : [LedgerAccountCode::MarketerPayable, LedgerAccount::OWNER_MARKETER, $id],
            default => null,
        };
    }

    private function account(string $table, string $key, string $code, LedgerAccountCode $accountCode, string $ownerType, int $ownerId, string $bucket, string $currency, ?string $balance): int
    {
        $natural = ['code' => $accountCode->value, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'bucket' => $bucket, 'currency' => $currency];
        $values = $natural + ['normal_side' => $accountCode->normalSide(), 'created_at' => now()->startOfDay(), 'updated_at' => now()->startOfDay()];

        if ($balance !== null) {
            $values['balance'] = $balance;
        }

        return $this->writer->upsert($table, $key, $code, 'ledger_accounts', $values, $natural, countMetrics: false);
    }

    /**
     * One balanced legacy transaction: the owner leg keeps `$balanceAfter`;
     * the platform `wallet_clearing` leg takes the other side.
     */
    private function post(string $code, string $sourceTable, string $sourceId, string $type, string $currency, string $amount, int $accountId, string $direction, string $balanceAfter, ?string $description, mixed $postedAt): void
    {
        if ($this->ids->find($sourceTable, $sourceId, $code, 'ledger_transactions') !== null) {
            $this->context->count($sourceTable, 'unchanged');

            return;
        }

        $clearing = $this->clearingAccount($code, $currency);
        $clearingBalance = $this->context->dryRun ? '0' : (string) MigrationContext::db($code)->table('ledger_accounts')->where('id', $clearing)->value('balance');
        $clearingDirection = $direction === 'credit' ? 'debit' : 'credit';
        $clearingAfter = $clearingDirection === 'debit' ? bcadd($clearingBalance, $amount, 4) : bcsub($clearingBalance, $amount, 4);

        $transactionId = $this->writer->upsert($sourceTable, $sourceId, $code, 'ledger_transactions', [
            'idempotency_key' => 'legacy:'.$sourceTable.':'.$sourceId,
            'type' => mb_substr($type, 0, 40),
            'currency' => $currency,
            'total' => $amount,
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'actor_type' => 'system',
            'posted_at' => $postedAt ?? now(),
        ]);
        $this->writer->upsert($sourceTable, $sourceId.':owner', $code, 'ledger_entries', ['ledger_transaction_id' => $transactionId, 'ledger_account_id' => $accountId, 'direction' => $direction, 'amount' => $amount, 'balance_after' => $balanceAfter, 'created_at' => $postedAt ?? now()], countMetrics: false);
        $this->writer->upsert($sourceTable, $sourceId.':clearing', $code, 'ledger_entries', ['ledger_transaction_id' => $transactionId, 'ledger_account_id' => $clearing, 'direction' => $clearingDirection, 'amount' => $amount, 'balance_after' => $clearingAfter, 'created_at' => $postedAt ?? now()], countMetrics: false);

        if (! $this->context->dryRun) {
            MigrationContext::db($code)->table('ledger_accounts')->where('id', $clearing)->update(['balance' => $clearingAfter]);
        }
    }

    private function clearingAccount(string $code, string $currency): int
    {
        $natural = ['code' => LedgerAccountCode::WalletClearing->value, 'owner_type' => LedgerAccount::OWNER_PLATFORM, 'owner_id' => 0, 'bucket' => 'main', 'currency' => $currency];
        $existing = $this->context->dryRun ? null : MigrationContext::db($code)->table('ledger_accounts')->where($natural)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->writer->upsert('ledger_accounts', 'wallet_clearing:'.$currency, $code, 'ledger_accounts', $natural + ['normal_side' => 'debit', 'balance' => '0', 'created_at' => now(), 'updated_at' => now()], $natural, countMetrics: false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    private function ledgerGroup(string $table, string $groupId, Collection $entries): void
    {
        $reject = function (string $reason, string $detail, ?string $code = null) use ($table, $entries): void {
            foreach ($entries as $entry) {
                $this->quarantine->put($table, (string) $entry['id'], $reason, $detail, $code, $entry);
            }
        };
        $currencies = $entries->pluck('currency')->map(fn ($currency): string => strtoupper((string) $currency))->unique();

        if ($currencies->count() !== 1) {
            $reject('ledger_unbalanced', "group {$groupId} mixes currencies");

            return;
        }

        $currency = $currencies->first();
        $code = $this->countries->codeForCurrency($currency);
        $debits = $entries->reduce(fn (string $sum, array $entry): string => bcadd($sum, (string) ($entry['debit'] ?? '0'), 4), '0');
        $credits = $entries->reduce(fn (string $sum, array $entry): string => bcadd($sum, (string) ($entry['credit'] ?? '0'), 4), '0');

        if ($code === null) {
            $reject('country_unresolved', "group {$groupId} currency {$currency} is not a launched country's currency");

            return;
        }

        if (bccomp($debits, $credits, 4) !== 0) {
            $reject('ledger_unbalanced', "group {$groupId}: debits {$debits} ≠ credits {$credits}", $code);

            return;
        }

        if (! $this->context->allows($code)) {
            $this->context->count($table, 'skipped_other_country', $entries->count());

            return;
        }

        $legs = [];

        foreach ($entries as $entry) {
            $accountCode = self::ACCOUNTS[$entry['account_type']] ?? null;

            if ($accountCode === null) {
                $reject('account_unmapped', "account type {$entry['account_type']} has no new equivalent", $code);

                return;
            }

            $holder = $this->holder($entry, $code);

            if ($holder === null) {
                $reject('owner_unresolved', "account holder {$entry['account_holder_type']} {$entry['account_holder_id']} not migrated to {$code}", $code);

                return;
            }

            [$ownerType, $ownerId] = $holder;
            $amount = bccomp((string) ($entry['debit'] ?? '0'), '0', 4) > 0 ? (string) ($entry['debit'] ?? '0') : (string) ($entry['credit'] ?? '0');
            $legs[] = [$entry, $accountCode, $ownerType, $ownerId, bccomp((string) ($entry['debit'] ?? '0'), '0', 4) > 0 ? 'debit' : 'credit', $amount];
        }

        if ($this->ids->find('ledger_groups', $groupId, $code, 'ledger_transactions') !== null) {
            $this->context->count($table, 'unchanged', $entries->count());

            return;
        }

        $write = function () use ($table, $groupId, $code, $currency, $debits, $legs, $entries): void {
            $transactionId = $this->writer->upsert('ledger_groups', $groupId, $code, 'ledger_transactions', [
                'idempotency_key' => 'legacy:ledger:'.$groupId,
                'type' => 'legacy_'.mb_substr((string) ($entries->first()['reference_type'] ?? 'ledger'), 0, 32),
                'currency' => $currency,
                'total' => $debits,
                'description' => mb_substr((string) ($entries->first()['description'] ?? 'Old platform ledger'), 0, 255),
                'actor_type' => 'system',
                'posted_at' => $entries->first()['created_at'] ?? now(),
            ], countMetrics: false);

            foreach ($legs as [$entry, $accountCode, $ownerType, $ownerId, $direction, $amount]) {
                $natural = ['code' => $accountCode->value, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'bucket' => 'legacy', 'currency' => $currency];
                $accountId = $this->writer->upsert('ledger_accounts', implode(':', $natural), $code, 'ledger_accounts', $natural + ['normal_side' => $accountCode->normalSide(), 'balance' => '0', 'created_at' => now(), 'updated_at' => now()], $natural, countMetrics: false);
                $current = $this->context->dryRun ? '0' : (string) MigrationContext::db($code)->table('ledger_accounts')->where('id', $accountId)->value('balance');
                $after = $direction === $accountCode->normalSide() ? bcadd($current, $amount, 4) : bcsub($current, $amount, 4);
                $this->writer->upsert($table, (string) $entry['id'], $code, 'ledger_entries', ['ledger_transaction_id' => $transactionId, 'ledger_account_id' => $accountId, 'direction' => $direction, 'amount' => $amount, 'balance_after' => $after, 'created_at' => $entry['created_at'] ?? now()]);

                if (! $this->context->dryRun) {
                    MigrationContext::db($code)->table('ledger_accounts')->where('id', $accountId)->update(['balance' => $after]);
                }
            }
        };

        $this->context->dryRun ? $write() : MigrationContext::db($code)->transaction($write);
        $this->context->sum($table, $currency, $debits);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: int}|null
     */
    private function holder(array $entry, string $code): ?array
    {
        $holder = (string) ($entry['account_holder_id'] ?? '');
        $find = fn (string $table, string $db, string $target): ?int => $this->ids->find($table, $holder, $db, $target);

        return match ($entry['account_holder_type']) {
            'customer', 'App\\Models\\Customer' => ($id = $find('customers', MigrationContext::CENTRAL, 'users')) === null ? null : [LedgerAccount::OWNER_CUSTOMER, $id],
            'vendor', 'App\\Models\\Vendor' => ($id = $find('vendors', $code, 'vendors')) === null ? null : [LedgerAccount::OWNER_VENDOR, $id],
            'marketer', 'App\\Models\\Marketer' => ($id = $find('marketers', $code, 'marketer_profiles')) === null ? null : [LedgerAccount::OWNER_MARKETER, $id],
            default => [LedgerAccount::OWNER_PLATFORM, 0],
        };
    }
}
