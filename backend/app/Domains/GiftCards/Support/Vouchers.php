<?php

namespace App\Domains\GiftCards\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Models\PromotionalCredit;
use App\Domains\Finance\Support\Wallets;
use App\Domains\GiftCards\Enums\VoucherStatus;
use App\Domains\GiftCards\Models\Voucher;
use App\Domains\GiftCards\Models\VoucherRedemption;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vouchers: admin codes worth promotional Nawy Credits. One redemption per
 * customer, `max_redemptions` in total (then used), expired daily with
 * gift cards (00:30).
 */
class Vouchers
{
    public function __construct(
        private StateMachine $machine,
        private Wallets $wallets,
        private GiftCodes $codes,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    public function create(int $adminUserId, string $code, string $label, Money $value, int $maxRedemptions, int $creditExpiryDays, CarbonInterface $expiresAt): Voucher
    {
        $voucher = Voucher::query()->create([
            'code_hash' => $this->codes->hash($code),
            'label' => $label,
            'status' => VoucherStatus::Active,
            'currency' => $value->getCurrency()->getCurrencyCode(),
            'value' => $this->money->toStorage($value),
            'max_redemptions' => $maxRedemptions,
            'credit_expiry_days' => $creditExpiryDays,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $adminUserId,
        ]);
        $this->machine->created($voucher, ActorType::Admin, $adminUserId);

        return $voucher;
    }

    public function redeem(int $customerUserId, string $code): PromotionalCredit
    {
        $country = $this->countryContext->current();

        return DB::connection((new Voucher)->getConnectionName())->transaction(function () use ($customerUserId, $code, $country): PromotionalCredit {
            $voucher = Voucher::query()->where('code_hash', $this->codes->hash($code))->lockForUpdate()->first();

            if ($voucher === null || $voucher->currency !== $country->currency || $voucher->status !== VoucherStatus::Active || $voucher->expires_at->isPast()) {
                throw new TransitionRefused(__('This voucher code is not valid.'));
            }

            if (VoucherRedemption::query()->where('voucher_id', $voucher->id)->where('customer_user_id', $customerUserId)->exists()) {
                throw new TransitionRefused(__('You already used this voucher.'));
            }

            $credit = $this->wallets->grantPromotional(
                $customerUserId,
                $this->money->of((string) $voucher->value, $voucher->currency),
                "voucher:{$voucher->id}:{$customerUserId}",
                'voucher',
                now()->addDays($voucher->credit_expiry_days),
                actorType: ActorType::Customer,
                actorUserId: $customerUserId,
            );
            VoucherRedemption::query()->create(['voucher_id' => $voucher->id, 'customer_user_id' => $customerUserId, 'promotional_credit_id' => $credit->id]);
            $voucher->forceFill(['redemptions_count' => $voucher->redemptions_count + 1])->save();

            if ($voucher->redemptions_count >= $voucher->max_redemptions) {
                $this->machine->move($voucher, VoucherStatus::Used, ActorType::System);
            }

            return $credit;
        });
    }

    /**
     * Admin void (wave 2): no new redemptions; credits already granted stay.
     */
    public function void(Voucher $voucher, int $adminUserId, string $reason): Voucher
    {
        if ($voucher->status !== VoucherStatus::Active) {
            throw new TransitionRefused(__('Only an active voucher can be voided.'));
        }

        return $this->machine->move($voucher, VoucherStatus::Cancelled, ActorType::Admin, $adminUserId, $reason);
    }

    public function expire(): int
    {
        $expired = 0;

        Voucher::query()->where('status', VoucherStatus::Active)->where('expires_at', '<=', now())->each(function (Voucher $voucher) use (&$expired): void {
            $this->machine->move($voucher, VoucherStatus::Expired, ActorType::System);
            $expired++;
        });

        return $expired;
    }
}
