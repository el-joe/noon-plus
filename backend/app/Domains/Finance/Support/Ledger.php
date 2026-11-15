<?php

namespace App\Domains\Finance\Support;

use App\Domains\Finance\Exceptions\InsufficientFunds;
use App\Domains\Finance\Exceptions\UnbalancedPosting;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The posting service of the double-entry ledger (ADR 4). Every financial
 * event is one transaction: debits equal credits, one currency, at least
 * two legs, unique idempotency key (posting the same key again returns the
 * first transaction and writes nothing). Account rows are locked in id
 * order while their balance projections are updated, and wallet / gift-card
 * balances can never go below zero.
 */
class Ledger
{
    public function __construct(private MoneyFactory $money) {}

    public function post(
        string $idempotencyKey,
        string $type,
        Posting $posting,
        ActorType $actorType = ActorType::System,
        ?int $actorUserId = null,
        ?Model $reference = null,
        ?string $description = null,
    ): LedgerTransaction {
        $legs = $posting->legs();
        $this->assertBalanced($legs);
        $currency = $legs[0]['amount']->getCurrency()->getCurrencyCode();

        return DB::connection((new LedgerTransaction)->getConnectionName())->transaction(function () use ($idempotencyKey, $type, $legs, $currency, $actorType, $actorUserId, $reference, $description): LedgerTransaction {
            $existing = LedgerTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $accountIds = [];

            foreach ($legs as $index => $leg) {
                $accountIds[$index] = $this->account($leg['account'], $currency)->id;
            }

            $accounts = LedgerAccount::query()->whereKey(array_unique($accountIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = array_reduce($legs, fn (Money $sum, array $leg): Money => $leg['direction'] === 'debit' ? $sum->plus($leg['amount']) : $sum, $this->money->zero($currency));

            $transaction = LedgerTransaction::query()->create([
                'idempotency_key' => $idempotencyKey,
                'type' => $type,
                'currency' => $currency,
                'total' => $this->money->toStorage($total),
                'reference_type' => $reference === null ? null : Str::snake(class_basename($reference)),
                'reference_id' => $reference?->getKey(),
                'description' => $description === null ? null : mb_substr($description, 0, 255),
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'posted_at' => now(),
            ]);

            foreach ($legs as $index => $leg) {
                $account = $accounts->get($accountIds[$index]);
                $delta = $leg['direction'] === $account->normal_side ? $leg['amount'] : $leg['amount']->negated();
                $balance = $this->money->of((string) $account->balance, $currency)->plus($delta);

                if ($balance->isNegative() && $account->code->mustStayNonNegative()) {
                    throw new InsufficientFunds(__('Not enough balance for this payment.'));
                }

                $account->forceFill(['balance' => $this->money->toStorage($balance)])->save();

                LedgerEntry::query()->create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $account->id,
                    'direction' => $leg['direction'],
                    'amount' => $this->money->toStorage($leg['amount']),
                    'balance_after' => $this->money->toStorage($balance),
                ]);
            }

            return $transaction;
        });
    }

    /**
     * The account row, created on first use.
     */
    public function account(AccountRef $ref, string $currency): LedgerAccount
    {
        return LedgerAccount::query()->createOrFirst([
            'code' => $ref->code,
            'owner_type' => $ref->ownerType,
            'owner_id' => $ref->ownerId,
            'bucket' => $ref->bucket,
            'currency' => $currency,
        ], ['normal_side' => $ref->code->normalSide()]);
    }

    public function balance(AccountRef $ref, string $currency): Money
    {
        $balance = LedgerAccount::query()->where([
            'code' => $ref->code,
            'owner_type' => $ref->ownerType,
            'owner_id' => $ref->ownerId,
            'bucket' => $ref->bucket,
            'currency' => $currency,
        ])->value('balance');

        return $this->money->of((string) ($balance ?? '0'), $currency);
    }

    /**
     * Recompute an account balance from its entries (projection check).
     */
    public function balanceFromEntries(LedgerAccount $account): Money
    {
        $sum = fn (string $direction): string => (string) $account->entries()->where('direction', $direction)->sum('amount');
        $debits = $this->money->of($sum('debit'), $account->currency);
        $credits = $this->money->of($sum('credit'), $account->currency);

        return $account->normal_side === 'debit' ? $debits->minus($credits) : $credits->minus($debits);
    }

    /**
     * @param  array<int, array{account: AccountRef, direction: string, amount: Money}>  $legs
     */
    private function assertBalanced(array $legs): void
    {
        if (count($legs) < 2) {
            throw new UnbalancedPosting('A ledger transaction needs at least two legs.');
        }

        $currency = $legs[0]['amount']->getCurrency()->getCurrencyCode();
        $debits = Money::zero($currency);
        $credits = Money::zero($currency);

        foreach ($legs as $leg) {
            if ($leg['amount']->getCurrency()->getCurrencyCode() !== $currency) {
                throw new UnbalancedPosting('A ledger transaction cannot mix currencies.');
            }

            $leg['direction'] === 'debit' ? $debits = $debits->plus($leg['amount']) : $credits = $credits->plus($leg['amount']);
        }

        if (! $debits->isEqualTo($credits)) {
            throw new UnbalancedPosting("Debits {$debits} do not equal credits {$credits}.");
        }
    }
}
