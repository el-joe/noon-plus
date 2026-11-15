<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use Brick\Money\Money;
use Illuminate\Support\Str;

/**
 * Clawback recovery (MP §8/§13, FR-MKT-14). A reversed commission whose
 * money already left the spendable wallet sits as a negative balance in
 * the marketer's `clawback` bucket. Recovery moves the next spendable
 * earnings into it (Dr marketer spendable / Cr marketer clawback) and
 * marks the clawback conversions recovered, oldest first.
 */
class Clawbacks
{
    public function __construct(private Ledger $ledger, private MoneyFactory $money, private StateMachine $machine) {}

    public function recover(int $marketerProfileId, string $currency): Money
    {
        $owed = $this->ledger->balance(AccountRef::marketer($marketerProfileId, WalletBucket::Clawback), $currency)->negated();
        $spendable = $this->ledger->balance(AccountRef::marketer($marketerProfileId, WalletBucket::Spendable), $currency);

        if (! $owed->isPositive() || ! $spendable->isPositive()) {
            return $this->money->zero($currency);
        }

        $amount = Money::min($owed, $spendable);
        $this->ledger->post(
            "clawback-recovery:{$marketerProfileId}:".Str::uuid(),
            'clawback_recovery',
            (new Posting)->debit(AccountRef::marketer($marketerProfileId, WalletBucket::Spendable), $amount)->credit(AccountRef::marketer($marketerProfileId, WalletBucket::Clawback), $amount),
            description: 'Clawback recovered from earnings',
        );

        $left = $amount;

        foreach (MarketerConversion::query()->where('marketer_profile_id', $marketerProfileId)->where('status', ConversionStatus::ClawbackPending)->orderBy('id')->get() as $conversion) {
            if (! $left->isPositive()) {
                break;
            }

            $open = $this->money->of((string) $conversion->clawback_amount, $currency)->minus($this->money->of((string) $conversion->recovered_amount, $currency));
            $applied = Money::min($open, $left);
            $left = $left->minus($applied);
            $recovered = $this->money->of((string) $conversion->recovered_amount, $currency)->plus($applied);
            $conversion->forceFill(['recovered_amount' => $this->money->toStorage($recovered)])->save();

            if ($recovered->isGreaterThanOrEqualTo($this->money->of((string) $conversion->clawback_amount, $currency))) {
                $this->machine->move($conversion, ConversionStatus::Recovered, ActorType::System, null, 'Recovered from earnings');
            }
        }

        return $amount;
    }

    /**
     * Clawback recovery job: every marketer with an open clawback.
     */
    public function recoverAll(string $currency): int
    {
        $marketers = MarketerConversion::query()->where('status', ConversionStatus::ClawbackPending)->distinct()->pluck('marketer_profile_id');

        foreach ($marketers as $marketerId) {
            $this->recover((int) $marketerId, $currency);
        }

        return $marketers->count();
    }
}
