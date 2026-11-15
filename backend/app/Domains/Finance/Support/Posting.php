<?php

namespace App\Domains\Finance\Support;

use Brick\Money\Money;

/**
 * The legs of one ledger transaction, built before {@see Ledger::post()}.
 * Zero legs are dropped; a negative amount flips the leg to the other side.
 */
final class Posting
{
    /**
     * @var array<int, array{account: AccountRef, direction: string, amount: Money}>
     */
    private array $legs = [];

    public function debit(AccountRef $account, Money $amount): self
    {
        return $this->add($account, 'debit', $amount);
    }

    public function credit(AccountRef $account, Money $amount): self
    {
        return $this->add($account, 'credit', $amount);
    }

    /**
     * @return array<int, array{account: AccountRef, direction: string, amount: Money}>
     */
    public function legs(): array
    {
        return $this->legs;
    }

    private function add(AccountRef $account, string $direction, Money $amount): self
    {
        if ($amount->isZero()) {
            return $this;
        }

        if ($amount->isNegative()) {
            $direction = $direction === 'debit' ? 'credit' : 'debit';
            $amount = $amount->negated();
        }

        $this->legs[] = ['account' => $account, 'direction' => $direction, 'amount' => $amount];

        return $this;
    }
}
