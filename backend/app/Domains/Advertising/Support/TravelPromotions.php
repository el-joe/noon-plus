<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Domains\Payments\Support\PaymentRefunds;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paid travel placement (owner decision D14). Agencies list trips for free
 * and pay Nawy only for a promotion; there is no commission or other fee
 * (D11), and customer bookings stay payment-free (D8).
 *
 * The placement reuses the Advertising slot model: one `ad_slots` row with
 * placement `travel` whose `max_concurrent` is the cap of promoted trips a
 * list shows. Admin sells duration options ({@see TravelPromotionProduct},
 * price VAT included, per country). Buying opens a `pending_payment`
 * promotion and a card payment (payable `travel_promotion`, the gift-card /
 * Nawy One flow); on capture ({@see PaymentCaptured}) the money is booked
 * Dr gateway clearing / Cr ad revenue + VAT payable and the promotion goes
 * `active` for the paid window (queued after a live promotion of the same
 * package). The scheduled job expires ended windows and cancels unpaid
 * promotions; an admin may cancel (no money back) or refund (gateway refund
 * and the reverse posting).
 */
class TravelPromotions
{
    public const SLOT_CODE = 'travel_sponsored';

    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private Ledger $ledger,
        private VerticalSettings $settings,
    ) {}

    /**
     * The travel placement slot of this country (created on first use).
     */
    public function slot(): AdSlot
    {
        return AdSlot::query()->firstOrCreate(
            ['code' => self::SLOT_CODE],
            ['name' => 'Travel · sponsored trips', 'placement' => AdSlot::PLACEMENT_TRAVEL, 'price_per_day' => '0', 'max_concurrent' => max(1, $this->settings->int('travel.promotion_slots')), 'is_active' => true],
        );
    }

    /**
     * Open a promotion and its card payment. The package must be the
     * agency's own and live (active, agency active).
     *
     * @return array{0: TravelPromotion, 1: Payment}
     */
    public function buy(Vendor $agency, TravelPackage $package, TravelPromotionProduct $product, User $user, ?string $returnUrl = null): array
    {
        if ((int) $package->vendor_id !== (int) $agency->id) {
            throw new TransitionRefused(__('This trip is not yours.'));
        }

        if (! $this->isPromotable($package)) {
            throw new TransitionRefused(__('Only an active, published trip can be promoted.'));
        }

        if (! $product->is_active || ! $product->slot?->is_active || $product->slot->placement !== AdSlot::PLACEMENT_TRAVEL) {
            throw new TransitionRefused(__('This promotion is not on sale.'));
        }

        $country = $this->countryContext->current();
        $amount = $this->money->of((string) $product->price, $country->currency);

        if (! $amount->isPositive()) {
            throw new TransitionRefused(__('This promotion is not on sale.'));
        }

        $promotion = DB::connection((new TravelPromotion)->getConnectionName())->transaction(function () use ($agency, $package, $product, $user, $amount, $country): TravelPromotion {
            $promotion = new TravelPromotion([
                'number' => 'TP-'.uniqid(),
                'vendor_id' => $agency->id,
                'travel_package_id' => $package->id,
                'travel_promotion_product_id' => $product->id,
                'ad_slot_id' => $product->ad_slot_id,
                'duration_days' => $product->duration_days,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'amount' => $this->money->toStorage($amount),
                'bought_by_user_id' => $user->id,
            ]);
            $promotion->forceFill(['status' => TravelPromotionStatus::PendingPayment])->save();
            $promotion->forceFill(['number' => 'TP-'.$country->iso2.'-'.str_pad((string) $promotion->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->machine->created($promotion, ActorType::Agency, $user->id);

            return $promotion;
        });

        return [$promotion, $this->startPayment($promotion, $user, $returnUrl)];
    }

    /**
     * Pay again for a promotion still waiting for payment (the first card
     * attempt failed or was abandoned). A pending attempt is checked with
     * the gateway first, so a promotion is never paid twice.
     */
    public function pay(TravelPromotion $promotion, User $user, ?string $returnUrl = null): Payment
    {
        foreach ($promotion->payments()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Authorized])->get() as $pending) {
            app(PaymentAttempts::class)->refresh($pending, ActorType::Agency, $user->id, 'retry');
        }

        if ($promotion->refresh()->status !== TravelPromotionStatus::PendingPayment) {
            throw new TransitionRefused(__('This promotion is already paid or closed.'));
        }

        if (! $this->isPromotable($promotion->package()->firstOrFail())) {
            throw new TransitionRefused(__('Only an active, published trip can be promoted.'));
        }

        return $this->startPayment($promotion, $user, $returnUrl);
    }

    /**
     * Called inside the payment capture ({@see PaymentCaptured}): book the
     * revenue with VAT and start the paid window.
     */
    public function paid(Payment $payment): void
    {
        $promotion = TravelPromotion::query()->lockForUpdate()->find($payment->payable_id);

        if ($promotion === null) {
            return;
        }

        if ($promotion->status !== TravelPromotionStatus::PendingPayment) {
            Log::warning('Travel promotion paid while not awaiting payment — refund needed', ['promotion' => $promotion->number, 'payment' => $payment->reference]);

            return;
        }

        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);
        $vat = $this->vatIn($amount);
        $posting = (new Posting)->debit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount)
            ->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount->minus($vat));

        if ($vat->isPositive()) {
            $posting->credit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat);
        }

        $this->ledger->post("travel-promotion:{$promotion->id}", TravelPromotion::PAYABLE, $posting, ActorType::Agency, $payment->customer_user_id, $promotion, "Travel promotion {$promotion->number}");

        $queuedAfter = TravelPromotion::query()->where('travel_package_id', $promotion->travel_package_id)->whereKeyNot($promotion->id)
            ->where('status', TravelPromotionStatus::Active)->where('ends_at', '>', now())->max('ends_at');
        $starts = $queuedAfter === null ? CarbonImmutable::now() : CarbonImmutable::parse($queuedAfter);

        $this->machine->move($promotion, TravelPromotionStatus::Active, ActorType::Gateway, null, 'Payment captured', [
            'paid_at' => now(),
            'vat_amount' => $this->money->toStorage($vat),
            'starts_at' => $starts,
            'ends_at' => $starts->addDays($promotion->duration_days),
        ], ['payment' => $payment->reference]);
    }

    /**
     * TravelPromotionExpiryJob (per country): ended windows expire; a
     * promotion still unpaid after `travel.promotion_payment_minutes` is
     * checked with the gateway once more and otherwise cancelled.
     *
     * @return array{expired: int, cancelled: int}
     */
    public function expire(): array
    {
        $result = ['expired' => 0, 'cancelled' => 0];

        TravelPromotion::query()->where('status', TravelPromotionStatus::Active)->where('ends_at', '<=', now())->each(function (TravelPromotion $promotion) use (&$result): void {
            $this->machine->move($promotion, TravelPromotionStatus::Expired, ActorType::System, null, 'Paid window ended');
            $result['expired']++;
        });

        $cutoff = now()->subMinutes(max(1, $this->settings->int('travel.promotion_payment_minutes')));
        TravelPromotion::query()->where('status', TravelPromotionStatus::PendingPayment)->where('created_at', '<=', $cutoff)->each(function (TravelPromotion $promotion) use (&$result): void {
            foreach ($promotion->payments()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Authorized])->get() as $pending) {
                app(PaymentAttempts::class)->refresh($pending, source: 'promotion-expiry');
            }

            if ($promotion->refresh()->status === TravelPromotionStatus::PendingPayment) {
                $this->machine->move($promotion, TravelPromotionStatus::Cancelled, ActorType::System, null, 'Payment not completed', ['cancel_reason' => 'Payment not completed']);
                $result['cancelled']++;
            }
        });

        return $result;
    }

    /**
     * Admin stop without money back (e.g. a policy breach, or a promotion
     * that was never paid).
     */
    public function cancel(TravelPromotion $promotion, int $adminUserId, string $reason): TravelPromotion
    {
        return $this->machine->move($promotion, TravelPromotionStatus::Cancelled, ActorType::Admin, $adminUserId, $reason, [
            'cancel_reason' => mb_substr($reason, 0, 255),
            'ends_at' => $promotion->status === TravelPromotionStatus::Active ? now() : $promotion->ends_at,
        ]);
    }

    /**
     * Admin refund of the whole amount: the gateway refund, the reverse
     * posting (Dr ad revenue + VAT payable / Cr gateway clearing) and the
     * promotion stops.
     */
    public function refund(TravelPromotion $promotion, int $adminUserId, string $reason): TravelPromotion
    {
        if (! in_array(TravelPromotionStatus::Refunded, $promotion->status->allowedNext(), true)) {
            throw new TransitionRefused(__('Only a paid promotion can be refunded.'));
        }

        $payment = $promotion->payments()->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])->latest('id')->first()
            ?? throw new TransitionRefused(__('This promotion has no captured payment to refund.'));
        $amount = $this->money->of((string) $promotion->amount, $promotion->currency);

        app(PaymentRefunds::class)->refund($payment, $amount, "travel-promotion:{$promotion->id}:refund", ActorType::Admin, $adminUserId, $reason);

        $vat = $this->money->of((string) $promotion->vat_amount, $promotion->currency);
        $posting = (new Posting)->debit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount->minus($vat))
            ->credit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount);

        if ($vat->isPositive()) {
            $posting->debit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat);
        }

        $this->ledger->post("travel-promotion:{$promotion->id}:refund", 'travel_promotion_refund', $posting, ActorType::Admin, $adminUserId, $promotion, "Travel promotion {$promotion->number} refund");

        return $this->machine->move($promotion, TravelPromotionStatus::Refunded, ActorType::Admin, $adminUserId, $reason, [
            'cancel_reason' => mb_substr($reason, 0, 255),
            'ends_at' => $promotion->ends_at !== null && $promotion->ends_at->isFuture() ? now() : $promotion->ends_at,
        ]);
    }

    /**
     * A package may be promoted (and shown as promoted) only while it is
     * published and bookable and its agency is active.
     */
    public function isPromotable(TravelPackage $package): bool
    {
        return $package->status === PackageStatus::Active && $package->vendor()->where('status', 'active')->exists();
    }

    /**
     * VAT inside a VAT-inclusive amount at the country rate (half-up, the
     * currency's own scale: 2 or 3 decimals).
     */
    public function vatIn(Money $gross): Money
    {
        $rate = (string) $this->countryContext->current()->tax_rate;

        return $gross->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);
    }

    /**
     * `$returnUrl`: the app deep link the gateway return goes back to (in-app purchase), null for the web panel.
     */
    private function startPayment(TravelPromotion $promotion, User $user, ?string $returnUrl = null): Payment
    {
        $country = $this->countryContext->current();
        $amount = $this->money->of((string) $promotion->amount, $promotion->currency);
        $option = app(PaymentMethodCatalog::class)->find($country, 'card', $amount);

        if ($option === null || ! $option['method']->isGateway() || $option['method']->type() !== 'card') {
            throw new TransitionRefused(__('Card payment is not available right now. Try again later.'));
        }

        $package = $promotion->package()->firstOrFail();

        return app(PaymentAttempts::class)->startForPayable(TravelPromotion::PAYABLE, $promotion->id, $promotion->number, $amount, $option['method'], $user, 'Nawy travel promotion · '.$package->title_en, $returnUrl);
    }
}
