<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Marketers\Enums\WithdrawalStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Models\MarketerWithdrawal;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Marketer wallet withdrawals (lifecycle catalogue §3.2): request (amount ≤
 * spendable, ≥ `marketer.withdrawal_minimum`, no open clawback) moves the
 * amount spendable → reserved; reject gives it back; process pays it out
 * (Dr marketer reserved / Cr bank clearing). Four-eyes is not required
 * (proposed).
 */
class Withdrawals
{
    public function __construct(private Ledger $ledger, private MoneyFactory $money, private StateMachine $machine, private MarketingSettings $settings) {}

    /**
     * @return array{pending: Money, spendable: Money, reserved: Money, clawback: Money}
     */
    public function balances(MarketerProfile $marketer, string $currency): array
    {
        $balance = fn (WalletBucket $bucket): Money => $this->ledger->balance(AccountRef::marketer($marketer->id, $bucket), $currency);

        return [
            'pending' => $balance(WalletBucket::Pending),
            'spendable' => $balance(WalletBucket::Spendable),
            'reserved' => $balance(WalletBucket::Reserved),
            'clawback' => $balance(WalletBucket::Clawback),
        ];
    }

    /**
     * @param  array<string, string>|null  $payoutDetails
     */
    public function request(MarketerProfile $marketer, Money $amount, ?array $payoutDetails = null, ?int $userId = null): MarketerWithdrawal
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        return DB::connection($marketer->getConnectionName())->transaction(function () use ($marketer, $amount, $payoutDetails, $userId, $currency): MarketerWithdrawal {
            MarketerProfile::query()->lockForUpdate()->findOrFail($marketer->id);
            $balances = $this->balances($marketer, $currency);
            $minimum = $this->money->of((string) $this->settings->get('withdrawal_minimum'), $currency);

            if (! $amount->isPositive() || $amount->isLessThan($minimum)) {
                throw new TransitionRefused(__('The amount is below the minimum withdrawal.'));
            }

            if ($balances['clawback']->isNegative()) {
                throw new TransitionRefused(__('A clawback is being recovered; withdrawals resume once it is settled.'));
            }

            if ($amount->isGreaterThan($balances['spendable'])) {
                throw new TransitionRefused(__('The amount is more than your spendable balance.'));
            }

            $withdrawal = MarketerWithdrawal::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'marketer_profile_id' => $marketer->id,
                'currency' => $currency,
                'amount' => $this->money->toStorage($amount),
                'status' => WithdrawalStatus::Pending,
                'payout_details' => $payoutDetails,
            ]);
            $withdrawal->forceFill(['number' => sprintf('WD-%06d', $withdrawal->id)])->save();
            $this->ledger->post("withdrawal:{$withdrawal->id}:request", 'marketer_withdrawal', (new Posting)
                ->debit(AccountRef::marketer($marketer->id, WalletBucket::Spendable), $amount)
                ->credit(AccountRef::marketer($marketer->id, WalletBucket::Reserved), $amount), reference: $withdrawal, description: "Withdrawal {$withdrawal->number}");
            $this->machine->created($withdrawal, ActorType::Marketer, $userId);

            return $withdrawal;
        });
    }

    public function approve(MarketerWithdrawal $withdrawal, int $adminUserId): MarketerWithdrawal
    {
        return $this->machine->move($withdrawal, WithdrawalStatus::Approved, ActorType::Admin, $adminUserId, null, ['approved_by_user_id' => $adminUserId]);
    }

    public function reject(MarketerWithdrawal $withdrawal, int $adminUserId, string $reason): MarketerWithdrawal
    {
        return DB::connection($withdrawal->getConnectionName())->transaction(function () use ($withdrawal, $adminUserId, $reason): MarketerWithdrawal {
            $this->machine->move($withdrawal, WithdrawalStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['reason' => mb_substr($reason, 0, 255)]);
            $amount = $this->money->of((string) $withdrawal->amount, $withdrawal->currency);
            $this->ledger->post("withdrawal:{$withdrawal->id}:reject", 'marketer_withdrawal_rejected', (new Posting)
                ->debit(AccountRef::marketer($withdrawal->marketer_profile_id, WalletBucket::Reserved), $amount)
                ->credit(AccountRef::marketer($withdrawal->marketer_profile_id, WalletBucket::Spendable), $amount), reference: $withdrawal);

            return $withdrawal;
        });
    }

    public function process(MarketerWithdrawal $withdrawal, int $adminUserId): MarketerWithdrawal
    {
        return DB::connection($withdrawal->getConnectionName())->transaction(function () use ($withdrawal, $adminUserId): MarketerWithdrawal {
            $this->machine->move($withdrawal, WithdrawalStatus::Processed, ActorType::Admin, $adminUserId, null, ['processed_at' => now()]);
            $amount = $this->money->of((string) $withdrawal->amount, $withdrawal->currency);
            $this->ledger->post("withdrawal:{$withdrawal->id}:process", 'marketer_withdrawal_paid', (new Posting)
                ->debit(AccountRef::marketer($withdrawal->marketer_profile_id, WalletBucket::Reserved), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::BankClearing), $amount), reference: $withdrawal);

            return $withdrawal;
        });
    }
}
