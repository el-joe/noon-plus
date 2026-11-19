<?php

namespace App\Domains\Finance\Support;

use App\Domains\Campaigns\Support\CampaignLedger;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Enums\VendorSubscriptionStatus;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Finance\Notifications\VendorSubscriptionNotice;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor subscriptions (FR-FIN-7, SRS §4.1.12, §9; lifecycle catalogue §3.6).
 *
 * - Subscribe: a store's first subscription to a plan with trial days starts
 *   `trialing`; otherwise it is `active` and the first period is invoiced.
 * - Invoices: one per period, the plan price incl. VAT. Paid either by
 *   deduction from the next payout statement (preference `payout`, the
 *   default: Dr vendor / Cr subscription revenue + VAT, statement line
 *   "Subscription") or by card through the gateway payable flow
 *   (preference `card`: Dr provider clearing / Cr revenue + VAT), due
 *   `subscription.grace_days` (7) after issue.
 * - Billing job (`nawy:subscriptions:bill`, daily 02:15 admin time): ends
 *   cancel-at-period-end subscriptions, renews the others (applies a plan
 *   change, invoices the next period: monthly plans are invoiced monthly),
 *   moves subscriptions with an invoice unpaid after its due date to
 *   `past_due` (notified + mailed), sends a final notice the day before the
 *   grace runs out and, after `subscription.grace_days` more, expires them
 *   (pending invoices void) — or, when the country sets
 *   `subscription.deduct_unpaid_from_payout`, deducts the invoice from the
 *   next payout and reactivates.
 * - Auto-renew (opt-in, card preference): each card invoice is charged to
 *   the store's chosen saved card (gateway token only) as soon as it is
 *   issued. A decline is retried `subscription.auto_charge_retries` (3)
 *   times, `subscription.auto_charge_retry_hours` (24) apart, by the billing
 *   job; every failure tells the store to pay by hand, and the invoice keeps
 *   its due date, so an unpaid one still goes past_due as above.
 * - PayPal in USD: the invoice keeps the local amount (ledger in the country
 *   currency, as for orders) and records what PayPal charged and the rate.
 * - Restrictions (catalogue "feature limits", proposed): while the store's
 *   latest subscription is past_due or expired it cannot submit listings;
 *   a plan's `max_active_listings` caps the store's active listings.
 */
class VendorSubscriptions
{
    public function __construct(
        private StateMachine $machine,
        private Ledger $ledger,
        private CampaignLedger $campaignLedger,
        private MoneyFactory $money,
        private FinanceSettings $settings,
        private CountryContext $countryContext,
    ) {}

    public function current(int $vendorId): ?VendorSubscription
    {
        return VendorSubscription::query()->where('vendor_id', $vendorId)
            ->whereIn('status', [VendorSubscriptionStatus::Trialing, VendorSubscriptionStatus::Active, VendorSubscriptionStatus::PastDue])
            ->with(['plan', 'pendingPlan'])->latest('id')->first();
    }

    /**
     * The store's latest subscription is past_due or expired (feature limits).
     */
    public function isRestricted(int $vendorId): bool
    {
        $latest = VendorSubscription::query()->where('vendor_id', $vendorId)->latest('id')->first(['id', 'status']);

        return in_array($latest?->status, [VendorSubscriptionStatus::PastDue, VendorSubscriptionStatus::Expired], true);
    }

    /**
     * The plan's cap on active listings, null without a cap or subscription.
     */
    public function listingLimit(int $vendorId): ?int
    {
        $subscription = $this->current($vendorId);

        return $subscription?->plan?->max_active_listings;
    }

    public function listingLimitReached(int $vendorId): bool
    {
        $limit = $this->listingLimit($vendorId);

        return $limit !== null && Listing::query()->where('vendor_id', $vendorId)
            ->whereIn('status', [ListingStatus::Active, ListingStatus::PendingReview])->count() >= $limit;
    }

    public function graceDays(): int
    {
        return (int) $this->settings->get('subscription.grace_days', 'membership_grace_days');
    }

    public function subscribe(Vendor $vendor, VendorSubscriptionPlan $plan, SubscriptionPaymentPreference $preference, ActorType $actorType, ?int $actorUserId): VendorSubscription
    {
        if (! $plan->is_active) {
            throw new TransitionRefused(__('This plan is not available.'));
        }

        if ($this->current($vendor->id) !== null) {
            throw new TransitionRefused(__('This store already has a subscription. Change its plan instead.'));
        }

        $isFirst = ! VendorSubscription::query()->where('vendor_id', $vendor->id)->exists();
        $now = CarbonImmutable::now();

        return DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $plan, $preference, $actorType, $actorUserId, $isFirst, $now): VendorSubscription {
            if ($isFirst && $plan->trial_days > 0) {
                $subscription = VendorSubscription::query()->create([
                    'vendor_id' => $vendor->id,
                    'vendor_subscription_plan_id' => $plan->id,
                    'status' => VendorSubscriptionStatus::Trialing,
                    'payment_preference' => $preference,
                    'current_period_start' => $now,
                    'current_period_end' => $now->addDays($plan->trial_days),
                    'trial_ends_at' => $now->addDays($plan->trial_days),
                ]);
                $this->machine->created($subscription, $actorType, $actorUserId, "Trial of {$plan->code}");

                return $subscription;
            }

            $subscription = VendorSubscription::query()->create([
                'vendor_id' => $vendor->id,
                'vendor_subscription_plan_id' => $plan->id,
                'status' => VendorSubscriptionStatus::Active,
                'payment_preference' => $preference,
                'current_period_start' => $now,
                'current_period_end' => $plan->interval->after($now),
            ]);
            $this->machine->created($subscription, $actorType, $actorUserId, "Subscribed to {$plan->code}");
            $this->issueInvoice($subscription, $plan, $now);

            return $subscription;
        })->refresh();
    }

    /**
     * Change plan: during the trial at once, otherwise from the next period.
     */
    public function changePlan(VendorSubscription $subscription, VendorSubscriptionPlan $plan): VendorSubscription
    {
        if (! $plan->is_active || ! $subscription->status->isOpen()) {
            throw new TransitionRefused(__('This plan is not available.'));
        }

        if ($plan->id === $subscription->vendor_subscription_plan_id) {
            $subscription->forceFill(['pending_plan_id' => null])->save();

            return $subscription;
        }

        $subscription->forceFill($subscription->status === VendorSubscriptionStatus::Trialing
            ? ['vendor_subscription_plan_id' => $plan->id, 'pending_plan_id' => null]
            : ['pending_plan_id' => $plan->id])->save();

        return $subscription->refresh();
    }

    public function setPreference(VendorSubscription $subscription, SubscriptionPaymentPreference $preference): VendorSubscription
    {
        $subscription->forceFill(['payment_preference' => $preference] + ($preference === SubscriptionPaymentPreference::Payout ? ['auto_renew' => false] : []))->save();

        return $subscription;
    }

    /**
     * Turn auto-renew on with one of the user's saved cards (the card
     * preference is implied) or off. Only a card the signed-in user saved,
     * not expired, of a card gateway, can be chosen.
     */
    public function setAutoRenew(VendorSubscription $subscription, bool $enabled, ?SavedPaymentCard $card = null): VendorSubscription
    {
        if (! $enabled) {
            $subscription->forceFill(['auto_renew' => false])->save();

            return $subscription;
        }

        if (! $subscription->status->isOpen()) {
            throw new TransitionRefused(__('This subscription has already ended.'));
        }

        if ($card === null || $card->isExpired() || PaymentMethod::tryFrom($card->gateway)?->type() !== 'card') {
            throw new TransitionRefused(__('Choose a saved card that has not expired.'));
        }

        $subscription->forceFill(['auto_renew' => true, 'saved_payment_card_id' => $card->id, 'payment_preference' => SubscriptionPaymentPreference::Card])->save();

        return $subscription;
    }

    /**
     * The cards the user can choose for auto-renew (gateway tokens only).
     *
     * @return Collection<int, SavedPaymentCard>
     */
    public function cardsFor(int $userId): Collection
    {
        return SavedPaymentCard::query()->where('customer_user_id', $userId)->orderByDesc('is_default')->latest('last_used_at')->get()
            ->filter(fn (SavedPaymentCard $card): bool => ! $card->isExpired() && PaymentMethod::tryFrom($card->gateway)?->type() === 'card')->values();
    }

    /**
     * Charge a pending invoice to the subscription's saved card. Captured →
     * the invoice is paid (via {@see invoicePaid()}); otherwise the failure
     * is kept, a retry is planned while retries are left, and the store is
     * told to pay by hand.
     */
    public function autoCharge(VendorSubscriptionInvoice $invoice): ?Payment
    {
        $subscription = $invoice->subscription()->first();

        if (! $invoice->isPending() || $subscription === null || ! $subscription->auto_renew) {
            $invoice->forceFill(['next_auto_charge_at' => null])->save();

            return null;
        }

        $card = $subscription->saved_payment_card_id === null ? null : SavedPaymentCard::query()->find($subscription->saved_payment_card_id);
        $payer = $card === null ? null : User::query()->find($card->customer_user_id);
        $attempts = $invoice->auto_charge_attempts + 1;
        $payment = null;

        if ($card === null || $payer === null) {
            $failure = __('The saved card for auto-renew was removed.');
        } else {
            $payment = app(PaymentAttempts::class)->chargeSavedCard(VendorSubscriptionInvoice::PAYABLE, $invoice->id, $invoice->number,
                $this->money->of((string) $invoice->amount, $invoice->currency), $card, $payer, __('Nawy seller plan :number', ['number' => $invoice->number]));
            $failure = $invoice->refresh()->isPending() ? ($payment->failure_message ?? __('The card was not charged.')) : null;
        }

        if ($failure === null) {
            $invoice->forceFill(['auto_charge_attempts' => $attempts, 'next_auto_charge_at' => null, 'auto_charge_failure' => null])->save();
            $this->notifyVendor($invoice->vendor_id, 'auto_charged', __('Subscription renewed'),
                __('Invoice :number for :amount was charged to your card ending :last4.', ['number' => $invoice->number, 'amount' => $invoice->amount.' '.$invoice->currency, 'last4' => $card->last4]));

            return $payment;
        }

        $retriesLeft = $card !== null && $attempts <= (int) $this->settings->get('subscription.auto_charge_retries', 'subscription_auto_charge_retries');
        $invoice->forceFill([
            'auto_charge_attempts' => $attempts,
            'auto_charge_failure' => mb_substr($failure, 0, 255),
            'next_auto_charge_at' => $retriesLeft ? now()->addHours(max(1, (int) $this->settings->get('subscription.auto_charge_retry_hours', 'subscription_auto_charge_retry_hours'))) : null,
        ])->save();
        $this->notifyVendor($invoice->vendor_id, 'auto_charge_failed', __('We could not charge your card for :number', ['number' => $invoice->number]),
            ($retriesLeft
                ? __(':reason We will try again on :date. You can also pay it now from Seller Center → Subscription.', ['reason' => $failure, 'date' => $invoice->next_auto_charge_at->toDateString()])
                : __(':reason Pay invoice :number by :date from Seller Center → Subscription to keep your plan.', ['reason' => $failure, 'number' => $invoice->number, 'date' => $invoice->due_at->toDateString()])));

        return $payment;
    }

    /**
     * The store cancels: a trial or a past_due subscription ends now (pending
     * invoices void), an active one at the end of the paid period. An admin
     * cancel ends it now.
     */
    public function cancel(VendorSubscription $subscription, ActorType $actorType, ?int $actorUserId, ?string $reason = null): VendorSubscription
    {
        if (! $subscription->status->isOpen()) {
            throw new TransitionRefused(__('This subscription has already ended.'));
        }

        if ($actorType === ActorType::Vendor && $subscription->status === VendorSubscriptionStatus::Active) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();

            return $subscription;
        }

        return $this->end($subscription, VendorSubscriptionStatus::Cancelled, $actorType, $actorUserId, $reason ?? 'Cancelled');
    }

    /**
     * Undo a cancel-at-period-end before the period ends.
     */
    public function resume(VendorSubscription $subscription): VendorSubscription
    {
        $subscription->forceFill(['cancel_at_period_end' => false])->save();

        return $subscription;
    }

    /**
     * Pay a pending invoice by card (gateway payable flow).
     */
    public function payByCard(VendorSubscriptionInvoice $invoice, User $payer, string $methodCode): Payment
    {
        if (! $invoice->isPending()) {
            throw new TransitionRefused(__('This invoice is already settled.'));
        }

        $country = $this->countryContext->current();
        $amount = $this->money->of((string) $invoice->amount, $invoice->currency);
        $option = app(PaymentMethodCatalog::class)->find($country, $methodCode, $amount);

        if ($option === null || ! $option['method']->isGateway()) {
            throw new TransitionRefused(__('This payment method is not available for subscription invoices.'));
        }

        return app(PaymentAttempts::class)->startForPayable(VendorSubscriptionInvoice::PAYABLE, $invoice->id, $invoice->number, $amount, $option['method'], $payer,
            __('Nawy seller plan :number', ['number' => $invoice->number]));
    }

    /**
     * Called inside the payment capture ({@see PaymentCaptured}).
     */
    public function invoicePaid(Payment $payment): void
    {
        $invoice = VendorSubscriptionInvoice::query()->lockForUpdate()->find($payment->payable_id);

        if ($invoice === null || ! $invoice->isPending()) {
            return;
        }

        // Ledger in the country currency, as for orders: a PayPal USD payment keeps the local
        // captured amount (charged amount converted back at the stored rate) in its clearing account.
        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);
        $description = $payment->charge_currency === null ? null : sprintf('%s %s at %s', $payment->charge_currency,
            $this->money->of((string) $payment->charge_amount, $payment->charge_currency)->getAmount(), rtrim(rtrim((string) $payment->exchange_rate, '0'), '.'));
        $this->postRevenue($invoice, app(PaymentCaptured::class)->clearingAccount($payment->method), $amount, "vendor-subscription-invoice:{$invoice->id}:paid", $description);
        $invoice->forceFill([
            'payment_id' => $payment->id,
            'paid_amount' => $payment->captured_amount,
            'charge_currency' => $payment->charge_currency,
            'charge_amount' => $payment->charge_amount,
            'exchange_rate' => $payment->exchange_rate,
            'next_auto_charge_at' => null,
        ])->save();
        $this->markPaid($invoice, $payment->method->value, ActorType::System, null);
    }

    /**
     * Deduct a pending invoice from the store's next payout statement.
     */
    public function deductFromPayout(VendorSubscriptionInvoice $invoice, ActorType $actorType = ActorType::System, ?int $actorUserId = null): VendorSubscriptionInvoice
    {
        if (! $invoice->isPending()) {
            throw new TransitionRefused(__('This invoice is already settled.'));
        }

        return DB::connection($invoice->getConnectionName())->transaction(function () use ($invoice, $actorType, $actorUserId): VendorSubscriptionInvoice {
            $amount = $this->money->of((string) $invoice->amount, $invoice->currency);
            $this->postRevenue($invoice, AccountRef::vendor($invoice->vendor_id), $amount, "vendor-subscription-invoice:{$invoice->id}:payout");
            $this->campaignLedger->balanceItem($invoice->vendor_id, VendorBalanceItem::TYPE_SUBSCRIPTION, $invoice, "Subscription {$invoice->number}", $amount->negated());
            $this->markPaid($invoice, 'payout', $actorType, $actorUserId);

            return $invoice->refresh();
        });
    }

    /**
     * Billing job for the current country.
     *
     * @return array{renewed: int, ended: int, past_due: int, expired: int, reminders: int, auto_charged: int, auto_charge_failed: int}
     */
    public function bill(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $grace = $this->graceDays();
        $counts = ['renewed' => 0, 'ended' => 0, 'past_due' => 0, 'expired' => 0, 'reminders' => 0, 'auto_charged' => 0, 'auto_charge_failed' => 0];

        VendorSubscription::query()->whereIn('status', [VendorSubscriptionStatus::Trialing, VendorSubscriptionStatus::Active])
            ->where('current_period_end', '<=', $now)->with(['plan', 'pendingPlan'])->orderBy('id')->get()
            ->each(function (VendorSubscription $subscription) use (&$counts): void {
                if ($subscription->cancel_at_period_end) {
                    $this->end($subscription, VendorSubscriptionStatus::Cancelled, ActorType::System, null, 'Cancelled at period end');
                    $counts['ended']++;

                    return;
                }

                $this->renew($subscription);
                $counts['renewed']++;
            });

        // Auto-renew retries (and first charges a crash may have left behind).
        VendorSubscriptionInvoice::query()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->whereNotNull('next_auto_charge_at')
            ->where('next_auto_charge_at', '<=', $now)->orderBy('id')->get()
            ->each(function (VendorSubscriptionInvoice $invoice) use (&$counts): void {
                $this->autoCharge($invoice);
                $counts[$invoice->refresh()->isPending() ? 'auto_charge_failed' : 'auto_charged']++;
            });

        VendorSubscriptionInvoice::query()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->where('due_at', '<=', $now)
            ->with('subscription')->orderBy('id')->get()
            ->each(function (VendorSubscriptionInvoice $invoice) use (&$counts): void {
                $subscription = $invoice->subscription;

                if (in_array($subscription->status, [VendorSubscriptionStatus::Trialing, VendorSubscriptionStatus::Active], true)) {
                    $this->machine->move($subscription, VendorSubscriptionStatus::PastDue, ActorType::System, null, "Invoice {$invoice->number} unpaid", ['past_due_since' => now()]);
                    $this->remind($invoice, 'past_due', __('Subscription past due'),
                        __('Invoice :number is unpaid. Pay it within :days days to keep your plan; listing submissions are paused until then.', ['number' => $invoice->number, 'days' => $this->graceDays()]));
                    $counts['past_due']++;
                }
            });

        VendorSubscription::query()->where('status', VendorSubscriptionStatus::PastDue)->whereNotNull('past_due_since')->orderBy('id')->get()
            ->each(function (VendorSubscription $subscription) use ($now, $grace, &$counts): void {
                $deadline = $subscription->past_due_since->addDays($grace);

                if ($deadline->lte($now)) {
                    if ((bool) $this->settings->get('subscription.deduct_unpaid_from_payout', 'subscription_deduct_unpaid_from_payout')) {
                        foreach ($subscription->invoices()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->get() as $invoice) {
                            $this->deductFromPayout($invoice);
                        }
                    } else {
                        $this->end($subscription, VendorSubscriptionStatus::Expired, ActorType::System, null, 'Grace period over');
                        $this->notifyVendor($subscription->vendor_id, 'expired', __('Subscription expired'),
                            __('Your plan expired because the invoice was not paid. Subscribe again to restore it.'));
                    }

                    $counts['expired']++;

                    return;
                }

                $invoice = $subscription->invoices()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->first();

                if ($invoice !== null && $deadline->subDay()->lte($now) && $invoice->reminders_sent < 3) {
                    $this->remind($invoice, 'final_notice', __('Final notice: subscription invoice :number', ['number' => $invoice->number]),
                        __('Pay invoice :number by tomorrow or your plan expires.', ['number' => $invoice->number]));
                    $counts['reminders']++;
                }
            });

        return $counts;
    }

    private function renew(VendorSubscription $subscription): void
    {
        $plan = $subscription->pendingPlan ?? $subscription->plan;
        $start = $subscription->current_period_end ?? CarbonImmutable::now();

        $invoice = DB::connection($subscription->getConnectionName())->transaction(function () use ($subscription, $plan, $start): VendorSubscriptionInvoice {
            $this->machine->move($subscription, VendorSubscriptionStatus::Active, ActorType::System, null,
                $subscription->status === VendorSubscriptionStatus::Trialing ? 'Trial ended' : 'Renewed', [
                    'vendor_subscription_plan_id' => $plan->id,
                    'pending_plan_id' => null,
                    'current_period_start' => $start,
                    'current_period_end' => $plan->interval->after($start),
                ]);

            return $this->issueInvoice($subscription->refresh(), $plan, $start);
        });

        // Auto-renew: the gateway is called outside the database transaction.
        if ($invoice->isPending() && $subscription->auto_renew) {
            $this->autoCharge($invoice);
        }
    }

    private function issueInvoice(VendorSubscription $subscription, VendorSubscriptionPlan $plan, CarbonImmutable $start): VendorSubscriptionInvoice
    {
        $country = $this->countryContext->current();
        $amount = $this->money->of((string) $plan->price, $country->currency);
        $invoice = VendorSubscriptionInvoice::query()->firstOrCreate(
            ['vendor_subscription_id' => $subscription->id, 'period_start' => $start],
            [
                'number' => 'PENDING-'.uniqid(),
                'vendor_id' => $subscription->vendor_id,
                'vendor_subscription_plan_id' => $plan->id,
                'currency' => $country->currency,
                'amount' => $this->money->toStorage($amount),
                'vat' => $this->money->toStorage($this->vat($amount)),
                'status' => VendorSubscriptionInvoice::STATUS_PENDING,
                'period_end' => $plan->interval->after($start),
                'due_at' => CarbonImmutable::now()->addDays($this->graceDays()),
            ],
        );

        if (str_starts_with($invoice->number, 'PENDING-')) {
            $invoice->forceFill(['number' => sprintf('SUB-%s-%06d', strtoupper($country->iso2), $invoice->id)])->save();
        }

        if (! $invoice->isPending()) {
            return $invoice;
        }

        if (! $amount->isPositive()) {
            $this->markPaid($invoice, 'free', ActorType::System, null);
        } elseif ($subscription->payment_preference === SubscriptionPaymentPreference::Payout) {
            $this->deductFromPayout($invoice);
        } elseif ($subscription->auto_renew) {
            // Charged to the saved card right after the transaction ({@see renew()}).
            $invoice->forceFill(['next_auto_charge_at' => now()])->save();
        } else {
            $this->remind($invoice, 'issued', __('Subscription invoice :number', ['number' => $invoice->number]),
                __('Invoice :number for :amount is due on :date. Pay it by card from Seller Center → Subscription.', [
                    'number' => $invoice->number, 'amount' => (string) $amount->getAmount().' '.$invoice->currency, 'date' => $invoice->due_at->toDateString(),
                ]));
        }

        return $invoice;
    }

    private function postRevenue(VendorSubscriptionInvoice $invoice, AccountRef $debit, Money $amount, string $key, ?string $chargeNote = null): void
    {
        $vat = $this->vat($amount);
        $posting = (new Posting)->debit($debit, $amount)->credit(AccountRef::platform(LedgerAccountCode::SubscriptionRevenue), $amount->minus($vat));

        if ($vat->isPositive()) {
            $posting->credit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat);
        }

        $this->ledger->post($key, 'vendor_subscription_invoice', $posting, reference: $invoice,
            description: "Subscription {$invoice->number}".($chargeNote === null ? '' : " (charged {$chargeNote})"));
    }

    private function markPaid(VendorSubscriptionInvoice $invoice, string $via, ActorType $actorType, ?int $actorUserId): void
    {
        $invoice->forceFill(['status' => VendorSubscriptionInvoice::STATUS_PAID, 'paid_at' => now(), 'paid_via' => $via])->save();
        $subscription = $invoice->subscription()->firstOrFail();

        if ($subscription->status === VendorSubscriptionStatus::PastDue
            && ! $subscription->invoices()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->exists()) {
            $this->machine->move($subscription, VendorSubscriptionStatus::Active, $actorType, $actorUserId, "Invoice {$invoice->number} paid", ['past_due_since' => null]);
        }
    }

    private function end(VendorSubscription $subscription, VendorSubscriptionStatus $to, ActorType $actorType, ?int $actorUserId, string $reason): VendorSubscription
    {
        return DB::connection($subscription->getConnectionName())->transaction(function () use ($subscription, $to, $actorType, $actorUserId, $reason): VendorSubscription {
            $subscription->invoices()->where('status', VendorSubscriptionInvoice::STATUS_PENDING)->update(['status' => VendorSubscriptionInvoice::STATUS_VOID]);

            return $this->machine->move($subscription, $to, $actorType, $actorUserId, $reason, ['ended_at' => now(), 'cancel_at_period_end' => false, 'pending_plan_id' => null]);
        });
    }

    private function vat(Money $amount): Money
    {
        $rate = (string) $this->countryContext->current()->tax_rate;

        return $amount->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);
    }

    private function remind(VendorSubscriptionInvoice $invoice, string $kind, string $subject, string $body): void
    {
        $invoice->forceFill(['reminders_sent' => $invoice->reminders_sent + 1, 'last_reminded_at' => now()])->save();
        $this->notifyVendor($invoice->vendor_id, $kind, $subject, $body);
    }

    private function notifyVendor(int $vendorId, string $kind, string $subject, string $body): void
    {
        $ownerId = Vendor::query()->whereKey($vendorId)->value('owner_user_id');
        $owner = $ownerId === null ? null : User::query()->find($ownerId);

        if ($owner === null) {
            return;
        }

        app(PanelNotifications::class)->notify($owner->id, 'partner', "subscription.{$kind}", $subject, $body, '/subscription');
        $owner->notify(new VendorSubscriptionNotice($kind, $subject, $body, Panel::find('partner')->url('subscription')));
    }
}
