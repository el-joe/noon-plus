<?php

namespace App\Domains\Membership\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\FinanceSettings;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Membership\Enums\MembershipStatus;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Models\MembershipInvoice;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\Listeners\AccountLifecycleEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Nawy One billing (MASTER_PLAN §7a, §10). Subscribe pays the first period
 * by card or Nawy Credits (a first-time member with plan trial days starts
 * trialing instead). Each paid invoice: Dr provider clearing / Cr membership
 * revenue + VAT. Renewals (job) charge Nawy Credits for the next period —
 * card tokens are not stored yet — and a failed charge makes it past_due;
 * after `subscription.grace_days` (7) it expires. Cancelling turns off
 * auto-renew; the member keeps the benefits until the period ends.
 */
class Memberships
{
    public function __construct(
        private StateMachine $machine,
        private Ledger $ledger,
        private MoneyFactory $money,
        private FinanceSettings $settings,
        private CountryContext $countryContext,
    ) {}

    public function current(int $customerUserId): ?Membership
    {
        return Membership::query()->where('customer_user_id', $customerUserId)->with('plan')->first();
    }

    /**
     * Free delivery for a member in the paid (or trial) period.
     */
    public function hasFreeShipping(?int $customerUserId): bool
    {
        if ($customerUserId === null) {
            return false;
        }

        $membership = $this->current($customerUserId);

        return $membership !== null && $membership->givesBenefits() && $membership->plan->free_shipping;
    }

    /**
     * @return array{0: Membership, 1: Payment|null}
     */
    public function subscribe(User $customer, MembershipPlan $plan, string $methodCode): array
    {
        if (! $plan->is_active) {
            throw new TransitionRefused(__('This plan is not available.'));
        }

        $existing = $this->current($customer->id);

        if ($existing !== null && ($existing->givesBenefits() || $existing->status === MembershipStatus::PastDue)) {
            throw new TransitionRefused(__('You are already a Nawy One member.'));
        }

        if ($existing === null && $plan->trial_days > 0) {
            $membership = Membership::query()->create([
                'customer_user_id' => $customer->id,
                'membership_plan_id' => $plan->id,
                'status' => MembershipStatus::Trialing,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($plan->trial_days),
                'auto_renew' => true,
            ]);
            $this->machine->created($membership, ActorType::Customer, $customer->id, 'Trial');
            app(AccountLifecycleEmails::class)->membership($membership, 'membership.started');

            return [$membership, null];
        }

        $method = $this->paymentMethod($customer, $plan, $methodCode);

        $membership = DB::connection($plan->getConnectionName())->transaction(function () use ($customer, $plan, $existing): Membership {
            if ($existing === null) {
                $membership = Membership::query()->create(['customer_user_id' => $customer->id, 'membership_plan_id' => $plan->id, 'status' => MembershipStatus::Incomplete, 'auto_renew' => true]);
                $this->machine->created($membership, ActorType::Customer, $customer->id);

                return $membership;
            }

            return $this->machine->move($existing, MembershipStatus::Incomplete, ActorType::Customer, $customer->id, null, ['membership_plan_id' => $plan->id, 'auto_renew' => true]);
        });

        $start = CarbonImmutable::now();
        $invoice = $this->invoice($membership, $plan, $start);
        $payment = app(PaymentAttempts::class)->startForPayable(MembershipInvoice::PAYABLE, $invoice->id, $invoice->number, $this->money->of((string) $invoice->amount, $invoice->currency), $method, $customer, __('Nawy One :plan', ['plan' => $plan->name()]));

        return [$membership->refresh(), $payment];
    }

    /**
     * Called inside the payment capture ({@see PaymentCaptured}).
     */
    public function invoicePaid(Payment $payment): void
    {
        $invoice = MembershipInvoice::query()->lockForUpdate()->find($payment->payable_id);

        if ($invoice === null || $invoice->status === 'paid') {
            return;
        }

        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);
        $rate = (string) $this->countryContext->current()->tax_rate;
        $vat = $amount->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);

        $this->ledger->post("membership-invoice:{$invoice->id}", 'membership_invoice', (new Posting)
            ->debit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount)
            ->credit(AccountRef::platform(LedgerAccountCode::MembershipRevenue), $amount->minus($vat))
            ->credit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat), ActorType::Customer, $payment->customer_user_id, $invoice, "Nawy One {$invoice->number}");

        $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $membership = $invoice->membership()->firstOrFail();
        $this->machine->move($membership, MembershipStatus::Active, ActorType::System, null, "Invoice {$invoice->number} paid", [
            'membership_plan_id' => $invoice->membership_plan_id,
            'current_period_start' => $invoice->period_start,
            'current_period_end' => $invoice->period_end,
            'past_due_since' => null,
        ]);
    }

    public function cancel(int $customerUserId): Membership
    {
        $membership = $this->current($customerUserId) ?? throw new TransitionRefused(__('You are not a Nawy One member.'));

        if (! in_array($membership->status, [MembershipStatus::Trialing, MembershipStatus::Active, MembershipStatus::PastDue], true)) {
            throw new TransitionRefused(__('This membership is not active.'));
        }

        $membership->forceFill(['auto_renew' => false])->save();
        app(AccountLifecycleEmails::class)->membership($membership, 'membership.cancelled');

        return $membership;
    }

    /**
     * Membership renewals job: charge the next period with Nawy Credits,
     * move non-renewing members to cancelled and lapse past_due ones after
     * the grace days. Returns how many memberships changed.
     */
    public function renew(): int
    {
        $grace = (int) $this->settings->get('subscription.grace_days', 'membership_grace_days');
        $changed = 0;

        Membership::query()
            ->with('plan')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('status', [MembershipStatus::Trialing, MembershipStatus::Active])->where('current_period_end', '<=', now()))
                ->orWhere('status', MembershipStatus::PastDue))
            ->orderBy('id')
            ->each(function (Membership $membership) use ($grace, &$changed): void {
                if ($membership->status !== MembershipStatus::PastDue && ! $membership->auto_renew) {
                    $this->machine->move($membership, MembershipStatus::Cancelled, ActorType::System, null, 'Auto-renew off');
                    $changed++;

                    return;
                }

                if ($membership->status === MembershipStatus::PastDue && $membership->past_due_since !== null && $membership->past_due_since->addDays($grace)->isPast()) {
                    $this->machine->move($membership, MembershipStatus::Expired, ActorType::System, null, 'Grace period over');
                    $changed++;

                    return;
                }

                $start = $membership->current_period_end ?? CarbonImmutable::now();
                $invoice = $this->invoice($membership, $membership->plan, $start);
                $customer = User::query()->findOrFail($membership->customer_user_id);
                $payment = app(PaymentAttempts::class)->startForPayable(MembershipInvoice::PAYABLE, $invoice->id, $invoice->number,
                    $this->money->of((string) $invoice->amount, $invoice->currency), PaymentMethod::Wallet, $customer, __('Nawy One renewal'));

                if ($payment->status !== PaymentStatus::Captured && $membership->refresh()->status !== MembershipStatus::PastDue) {
                    $this->machine->move($membership, MembershipStatus::PastDue, ActorType::System, null, 'Renewal payment failed', ['past_due_since' => now()]);
                }

                $changed++;
            });

        return $changed;
    }

    private function invoice(Membership $membership, MembershipPlan $plan, CarbonImmutable $start): MembershipInvoice
    {
        $currency = $this->countryContext->current()->currency;
        $invoice = MembershipInvoice::query()->firstOrCreate(
            ['membership_id' => $membership->id, 'period_start' => $start],
            [
                'number' => 'PENDING-'.uniqid(),
                'membership_plan_id' => $plan->id,
                'currency' => $currency,
                'amount' => $this->money->toStorage($this->money->of((string) $plan->price, $currency)),
                'status' => 'pending',
                'period_end' => $plan->interval->after($start),
            ],
        );

        if (str_starts_with($invoice->number, 'PENDING-')) {
            $invoice->forceFill(['number' => sprintf('N1-%s-%06d', $this->countryContext->current()->iso2, $invoice->id)])->save();
        }

        return $invoice;
    }

    private function paymentMethod(User $customer, MembershipPlan $plan, string $methodCode): PaymentMethod
    {
        $country = $this->countryContext->current();
        $option = app(PaymentMethodCatalog::class)->find($country, $methodCode, $this->money->of((string) $plan->price, $country->currency), $customer->id);

        if ($option === null || $option['method'] === PaymentMethod::Cod) {
            throw new TransitionRefused(__('This payment method is not available for Nawy One.'));
        }

        return $option['method'];
    }
}
