<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Enums\VendorSubscriptionStatus;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Finance\Notifications\VendorSubscriptionNotice;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRates;
use App\Domains\Finance\Support\SubscriptionInvoiceDocument;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Listings\Support\ListingSubmissionGates;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    $this->withoutVite();
    Cache::flush();
    Notification::fake();
});

function sellerPlan(array $attributes = []): VendorSubscriptionPlan
{
    return VendorSubscriptionPlan::query()->create($attributes + [
        'code' => 'pro', 'name_en' => 'Pro', 'name_ar' => 'احترافي', 'interval' => 'monthly', 'price' => '105.00', 'trial_days' => 0, 'is_active' => true,
    ]);
}

it('starts a first subscription as a trial, then bills the period by payout deduction with a statement line', function () {
    [$owner, $vendor] = sellerAccount();
    $plan = sellerPlan(['trial_days' => 14]);

    $this->actingAs($owner)->post(onPanel('partner', 'subscription'), ['plan_id' => $plan->id, 'payment_preference' => 'payout'])->assertSessionHasNoErrors();
    $subscription = VendorSubscription::query()->sole();
    expect($subscription->status)->toBe(VendorSubscriptionStatus::Trialing)->and(VendorSubscriptionInvoice::query()->count())->toBe(0);

    $this->travel(15)->days();
    $counts = app(VendorSubscriptions::class)->bill();

    $invoice = VendorSubscriptionInvoice::query()->sole();
    expect($counts['renewed'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Active)
        ->and($invoice->status)->toBe('paid')->and($invoice->paid_via)->toBe('payout')
        ->and((string) $invoice->vat)->toBe('5.0000')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::SubscriptionRevenue)))->toBe('100.00')
        ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('-105.00')
        ->and(VendorBalanceItem::query()->where('type', VendorBalanceItem::TYPE_SUBSCRIPTION)->value('amount'))->toEqual('-105.0000')
        ->and($subscription->transitions()->pluck('to_status')->all())->toBe(['trialing', 'active']);

    // Same day again: nothing new.
    expect(app(VendorSubscriptions::class)->bill()['renewed'])->toBe(0);
    $this->actingAs($owner)->get(onPanel('partner', 'subscription'))->assertOk()->assertSee($invoice->number)->assertSee('Pro')
        // Browser test BT-VEN-004: the store's history is in its country's time, not the admins' Oman time.
        ->assertSee('Times in '.Country::query()->where('code', 'uae')->value('timezone'))->assertDontSee('Times in Oman');
    $this->actingAs($owner)->get(onPanel('partner', "subscription/invoices/{$invoice->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    assertLedgerBalanced();
});

it('bills a card subscription through the gateway, moves an unpaid one to past_due, restricts listings and expires it after the grace', function () {
    [$owner, $vendor] = sellerAccount();
    $plan = sellerPlan();
    $subscriptions = app(VendorSubscriptions::class);
    $subscription = $subscriptions->subscribe($vendor, $plan, SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
    $invoice = VendorSubscriptionInvoice::query()->sole();

    expect($subscription->status)->toBe(VendorSubscriptionStatus::Active)->and($invoice->isPending())->toBeTrue();
    Notification::assertSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'issued');

    // Card payment: pending until the gateway reports the capture.
    $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'stripe'])->assertRedirect();
    $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->sole();
    expect($payment->customer_user_id)->toBe($owner->id);

    // Not paid: 8 days later it is past due, the store cannot submit listings.
    $this->travel(8)->days();
    expect($subscriptions->bill()['past_due'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::PastDue)
        ->and($subscriptions->isRestricted($vendor->id))->toBeTrue();
    expect(fn () => app(ListingSubmissionGates::class)->assertPasses($vendor->refresh()))->toThrow(ListingTransitionRefused::class);

    // Paying now brings it back.
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
    $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/verify"))->assertRedirect();
    expect($invoice->refresh()->status)->toBe('paid')->and($invoice->paid_via)->toBe('stripe')
        ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Active)
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::SubscriptionRevenue)))->toBe('100.00')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('5.00');
    app(ListingSubmissionGates::class)->assertPasses($vendor->refresh());

    // Next period unpaid: past due, final notice, then expired with the invoice void.
    $this->travel(30)->days();
    $subscriptions->bill();
    $next = VendorSubscriptionInvoice::query()->latest('id')->first();
    $this->travel(8)->days();
    $subscriptions->bill();
    expect($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::PastDue);
    $this->travel(6)->days();
    expect($subscriptions->bill()['reminders'])->toBe(1);
    $this->travel(1)->days();
    expect($subscriptions->bill()['expired'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Expired)
        ->and($next->refresh()->status)->toBe('void')
        ->and($subscriptions->isRestricted($vendor->id))->toBeTrue();
    Notification::assertSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'expired');
    assertLedgerBalanced();
});

it('lets the seller change plan from the next period, deduct an open invoice from the payout and cancel at period end', function () {
    [$owner, $vendor] = sellerAccount();
    $basic = sellerPlan(['code' => 'basic', 'name_en' => 'Basic', 'price' => '52.50', 'max_active_listings' => 1]);
    $pro = sellerPlan();
    $subscriptions = app(VendorSubscriptions::class);
    $subscription = $subscriptions->subscribe($vendor, $basic, SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
    $invoice = VendorSubscriptionInvoice::query()->sole();

    $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'payout'])->assertSessionHasNoErrors();
    expect($invoice->refresh()->paid_via)->toBe('payout')->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('-52.50');

    sellableListing('10.00', vendor: $vendor);
    expect($subscriptions->listingLimitReached($vendor->id))->toBeTrue();

    $this->actingAs($owner)->post(onPanel('partner', 'subscription/plan'), ['plan_id' => $pro->id])->assertSessionHasNoErrors();
    expect($subscription->refresh()->pending_plan_id)->toBe($pro->id)->and($subscription->vendor_subscription_plan_id)->toBe($basic->id);

    $this->actingAs($owner)->post(onPanel('partner', 'subscription/preference'), ['payment_preference' => 'payout'])->assertSessionHasNoErrors();
    $this->travel(32)->days();
    $subscriptions->bill();
    expect($subscription->refresh()->vendor_subscription_plan_id)->toBe($pro->id)
        ->and(VendorSubscriptionInvoice::query()->latest('id')->first()->amount)->toEqual('105.0000')
        ->and($subscriptions->listingLimitReached($vendor->id))->toBeFalse();

    $this->actingAs($owner)->post(onPanel('partner', 'subscription/cancel'))->assertSessionHasNoErrors();
    expect($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Active)->and($subscription->cancel_at_period_end)->toBeTrue();
    $this->travel(32)->days();
    expect($subscriptions->bill()['ended'])->toBe(1)
        ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Cancelled)
        ->and($subscriptions->isRestricted($vendor->id))->toBeFalse();

    // A store of another vendor cannot reach this invoice.
    [$stranger] = sellerAccount();
    $this->actingAs($stranger)->get(onPanel('partner', "subscription/invoices/{$invoice->id}/pdf"))->assertNotFound();
    assertLedgerBalanced();
});

it('lets finance manage plans and subscriptions in the admin', function () {
    $finance = adminAccount('uae', 'finance');
    $vendor = Vendor::factory()->create(['store_name' => 'Gulf Gadgets']);

    $this->actingAs($finance)->post(onPanel('admin', 'finance/subscription-plans'), [
        'code' => 'growth', 'name_en' => 'Growth', 'name_ar' => 'نمو', 'interval' => 'monthly', 'price' => '210.00', 'trial_days' => 0,
        'max_active_listings' => 500, 'features' => "Priority support\nAdvanced reports", 'is_active' => 1,
    ])->assertSessionHasNoErrors();
    $plan = VendorSubscriptionPlan::query()->sole();
    expect($plan->features)->toBe(['Priority support', 'Advanced reports']);
    $this->actingAs($finance)->post(onPanel('admin', 'finance/subscription-plans'), ['code' => 'growth', 'name_en' => 'x', 'name_ar' => 'x', 'interval' => 'weekly', 'price' => '-1', 'trial_days' => 0])
        ->assertSessionHasErrors(['code', 'interval', 'price']);

    $this->actingAs($finance)->post(onPanel('admin', 'finance/subscriptions'), ['vendor_id' => $vendor->id, 'plan_id' => $plan->id, 'payment_preference' => 'card'])->assertRedirect();
    $subscription = VendorSubscription::query()->sole();
    $invoice = VendorSubscriptionInvoice::query()->sole();
    $this->actingAs($finance)->get(onPanel('admin', 'finance/subscriptions'))->assertOk()->assertSee('Gulf Gadgets');
    $this->actingAs($finance)->get(onPanel('admin', "finance/subscriptions/{$subscription->id}"))->assertOk()->assertSee($invoice->number);
    $this->actingAs($finance)->get(onPanel('admin', 'finance/subscription-plans'))->assertOk()->assertSee('Growth');
    $this->actingAs($finance)->get(onPanel('admin', "finance/subscription-invoices/{$invoice->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($finance)->post(onPanel('admin', "finance/subscription-invoices/{$invoice->id}/deduct"))->assertSessionHasNoErrors();
    expect($invoice->refresh()->paid_via)->toBe('payout');
    $this->actingAs($finance)->post(onPanel('admin', "finance/subscriptions/{$subscription->id}/cancel"), ['reason' => 'Store closed'])->assertSessionHasNoErrors();
    expect($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Cancelled);

    $this->actingAs(adminAccount('uae', 'vendor_relations'))->get(onPanel('admin', 'finance/subscriptions'))->assertOk();
    $this->actingAs(adminAccount('uae', 'vendor_relations'))->post(onPanel('admin', 'finance/subscription-plans'), [])->assertForbidden();
    $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'finance/subscriptions'))->assertForbidden();
});

/**
 * Pays the subscription's first card invoice with Stripe (fake) and lets the
 * gateway tokenise the card, so the owner has a saved card.
 */
function payFirstInvoiceSavingCard($test, $owner, VendorSubscriptionInvoice $invoice): SavedPaymentCard
{
    $test->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'stripe'])->assertRedirect();
    $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->where('payable_id', $invoice->id)->latest('id')->first();
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
    FakeGateway::willTokenise($payment->gateway_reference, ['token' => 'pm_saved_4242', 'last4' => '4242', 'brand' => 'Visa', 'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y'), 'customer_ref' => 'cus_1']);
    $test->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/verify"))->assertRedirect();

    return SavedPaymentCard::query()->where('customer_user_id', $owner->id)->sole();
}

it('tells the store how the card payment ended when it comes back from the gateway, for its own invoices only', function () {
    // Browser test BT-VEN-005: a declined card came back to Subscription without a word.
    [$owner, $vendor] = sellerAccount();
    app(VendorSubscriptions::class)->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
    $invoice = VendorSubscriptionInvoice::query()->sole();
    $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'stripe'])->assertRedirect();
    $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->where('payable_id', $invoice->id)->sole();
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
    $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")->assertRedirect();

    $this->actingAs($owner)->followingRedirects()->get(onPanel('partner', 'subscription?payment='.$payment->reference))->assertOk()
        ->assertSee('data-payment-result="danger"', false);

    [$stranger] = sellerAccount();
    $this->actingAs($stranger)->followingRedirects()->get(onPanel('partner', 'subscription?payment='.$payment->reference))->assertOk()
        ->assertDontSee('data-payment-result', false);
});

it('shows the gateway result once: the return URL is dropped, so later actions do not repeat the banner', function () {
    // Browser test BT-VEN-009: `?payment=` stayed in the URL that back() returns to after every later action.
    [$owner, $vendor] = sellerAccount();
    app(VendorSubscriptions::class)->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
    $invoice = VendorSubscriptionInvoice::query()->sole();
    $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'stripe'])->assertRedirect();
    $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->where('payable_id', $invoice->id)->sole();
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
    $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")->assertRedirect();

    $this->actingAs($owner)->get(onPanel('partner', 'subscription?payment='.$payment->reference.'&status=captured'))
        ->assertRedirect(onPanel('partner', 'subscription'))->assertSessionHas('payment_result.tone', 'success');
    $this->get(onPanel('partner', 'subscription'))->assertOk()->assertSee('data-payment-result="success"', false);

    // A later action returns to the clean page: no banner any more.
    $this->from(onPanel('partner', 'subscription'))->post(onPanel('partner', 'subscription/preference'), ['payment_preference' => 'payout'])
        ->assertRedirect(onPanel('partner', 'subscription'));
    $this->get(onPanel('partner', 'subscription'))->assertOk()->assertDontSee('data-payment-result', false);
});

describe('auto-renew with a saved card', function () {
    it('charges the renewal invoice to the chosen saved card (token only) and books it like a card payment', function () {
        [$owner, $vendor] = sellerAccount();
        $subscriptions = app(VendorSubscriptions::class);
        $subscription = $subscriptions->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
        $card = payFirstInvoiceSavingCard($this, $owner, VendorSubscriptionInvoice::query()->sole());

        $this->actingAs($owner)->get(onPanel('partner', 'subscription'))->assertOk()->assertSee('Auto-renew with a saved card')->assertSee('•••• 4242', false);
        $this->actingAs($owner)->post(onPanel('partner', 'subscription/auto-renew'), ['enabled' => '1', 'saved_payment_card_id' => $card->id])->assertSessionHasNoErrors();
        expect($subscription->refresh())->auto_renew->toBeTrue()->saved_payment_card_id->toBe($card->id);

        $this->travel(31)->days();
        $counts = $subscriptions->bill();
        $renewal = VendorSubscriptionInvoice::query()->latest('id')->first();

        expect($counts['renewed'])->toBe(1)
            ->and($renewal->status)->toBe('paid')->and($renewal->paid_via)->toBe('stripe')->and($renewal->auto_charge_attempts)->toBe(1)
            ->and($renewal->payment_id)->not->toBeNull()
            ->and(FakeGateway::cardCharges())->toHaveCount(1)
            ->and(FakeGateway::cardCharges()[0])->toMatchArray(['amount' => '105.00', 'currency' => 'AED', 'token' => 'pm_saved_4242'])
            ->and(Payment::query()->find($renewal->payment_id)->status)->toBe(PaymentStatus::Captured)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::SubscriptionRevenue)))->toBe('200.00')
            ->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Active);
        Notification::assertSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'auto_charged');
        Notification::assertNotSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'issued' && str_contains($notice->subject, $renewal->number));
        assertLedgerBalanced();
    });

    it('retries a declined charge per settings, tells the store to pay by hand, and falls back to past_due when retries run out', function () {
        [$owner, $vendor] = sellerAccount();
        $subscriptions = app(VendorSubscriptions::class);
        $subscription = $subscriptions->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
        $card = payFirstInvoiceSavingCard($this, $owner, VendorSubscriptionInvoice::query()->sole());
        $subscriptions->setAutoRenew($subscription->refresh(), true, $card);

        // First period: declined once, paid on the retry the next day.
        FakeGateway::declineNextCardCharge();
        $this->travel(31)->days();
        $subscriptions->bill();
        $renewal = VendorSubscriptionInvoice::query()->latest('id')->first();
        expect($renewal->isPending())->toBeTrue()->and($renewal->auto_charge_attempts)->toBe(1)
            ->and($renewal->auto_charge_failure)->toBe('The card was declined.')
            ->and(round(now()->diffInHours($renewal->next_auto_charge_at)))->toEqual(24.0);
        Notification::assertSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'auto_charge_failed' && str_contains($notice->body, 'try again'));
        $this->actingAs($owner)->get(onPanel('partner', 'subscription'))->assertSee('The card was declined.');

        $this->travel(24 * 60 + 1)->minutes();
        expect($subscriptions->bill())->toMatchArray(['auto_charged' => 1, 'auto_charge_failed' => 0])
            ->and($renewal->refresh()->status)->toBe('paid')->and($renewal->auto_charge_attempts)->toBe(2);

        // Second period: 1 retry allowed by the country setting; both declined → manual pay → past_due after the due date.
        app(SettingsService::class)->set('subscription.auto_charge_retries', 1, $this->country);
        $this->travel(30)->days();
        FakeGateway::declineNextCardCharge();
        $subscriptions->bill();
        $next = VendorSubscriptionInvoice::query()->latest('id')->first();
        $this->travel(25)->hours();
        FakeGateway::declineNextCardCharge();
        expect($subscriptions->bill()['auto_charge_failed'])->toBe(1)
            ->and($next->refresh()->auto_charge_attempts)->toBe(2)->and($next->next_auto_charge_at)->toBeNull()->and($next->isPending())->toBeTrue();
        Notification::assertSentTo($owner, VendorSubscriptionNotice::class, fn (VendorSubscriptionNotice $notice): bool => $notice->kind === 'auto_charge_failed' && str_contains($notice->body, 'Pay invoice'));

        $this->travel(7)->days();
        expect($subscriptions->bill()['past_due'])->toBe(1)->and($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::PastDue)
            ->and(FakeGateway::cardCharges())->toHaveCount(4);

        // Paying by hand still works and reactivates.
        $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$next->id}/pay"), ['method' => 'payout'])->assertSessionHasNoErrors();
        expect($subscription->refresh()->status)->toBe(VendorSubscriptionStatus::Active);
        assertLedgerBalanced();
    });

    it('accepts only the user\'s own unexpired saved card and turns off with the payout preference or a removed card', function () {
        [$owner, $vendor] = sellerAccount();
        $subscriptions = app(VendorSubscriptions::class);
        $subscription = $subscriptions->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
        $card = payFirstInvoiceSavingCard($this, $owner, VendorSubscriptionInvoice::query()->sole());
        $someoneElse = SavedPaymentCard::query()->create(['customer_user_id' => $owner->id + 999, 'gateway' => 'stripe', 'gateway_token' => 'pm_x', 'token_hash' => hash('sha256', 'pm_x'), 'last4' => '1111']);
        $expired = SavedPaymentCard::query()->create(['customer_user_id' => $owner->id, 'gateway' => 'stripe', 'gateway_token' => 'pm_old', 'token_hash' => hash('sha256', 'pm_old'), 'last4' => '0000', 'exp_month' => 1, 'exp_year' => 2020]);

        $this->actingAs($owner)->post(onPanel('partner', 'subscription/auto-renew'), ['enabled' => '1', 'saved_payment_card_id' => $someoneElse->id])->assertSessionHasErrors('action');
        $this->actingAs($owner)->post(onPanel('partner', 'subscription/auto-renew'), ['enabled' => '1', 'saved_payment_card_id' => $expired->id])->assertSessionHasErrors('action');
        expect($subscription->refresh()->auto_renew)->toBeFalse();

        $this->actingAs($owner)->post(onPanel('partner', 'subscription/auto-renew'), ['enabled' => '1', 'saved_payment_card_id' => $card->id])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(onPanel('partner', 'subscription/preference'), ['payment_preference' => 'payout'])->assertSessionHasNoErrors();
        expect($subscription->refresh()->auto_renew)->toBeFalse();

        // Card removed after opting in: no charge, no retries, pay by hand.
        $this->actingAs($owner)->post(onPanel('partner', 'subscription/auto-renew'), ['enabled' => '1', 'saved_payment_card_id' => $card->id])->assertSessionHasNoErrors();
        $card->delete();
        $this->travel(31)->days();
        $subscriptions->bill();
        $renewal = VendorSubscriptionInvoice::query()->latest('id')->first();
        expect($renewal->isPending())->toBeTrue()->and($renewal->auto_charge_failure)->toBe('The saved card for auto-renew was removed.')
            ->and($renewal->next_auto_charge_at)->toBeNull()->and(FakeGateway::cardCharges())->toBeEmpty();
    });
});

describe('PayPal in USD', function () {
    it('books a PayPal USD invoice payment in the country currency and records the USD charge and rate', function () {
        app(ExchangeRates::class)->record('AED', 'USD', '0.2723', now()->subMinute());
        CountryGatewayConfig::query()->where('country_id', $this->country->id)->where('gateway', PaymentMethod::PayPal)->update(['is_enabled' => true]);
        [$owner, $vendor] = sellerAccount();
        app(VendorSubscriptions::class)->subscribe($vendor, sellerPlan(), SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
        $invoice = VendorSubscriptionInvoice::query()->sole();

        $this->actingAs($owner)->get(onPanel('partner', 'subscription'))->assertSee('value="paypal"', false);
        $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/pay"), ['method' => 'paypal'])->assertRedirect();
        $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->sole();
        expect($payment)->currency->toBe('AED')->amount->toBe('105.0000')->charge_currency->toBe('USD')->charge_amount->toBe('28.5900');

        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $this->actingAs($owner)->post(onPanel('partner', "subscription/invoices/{$invoice->id}/verify"))->assertRedirect();

        $invoice->refresh();
        $transaction = LedgerTransaction::query()->where('idempotency_key', "vendor-subscription-invoice:{$invoice->id}:paid")->sole();
        expect($invoice)->status->toBe('paid')->paid_via->toBe('paypal')->payment_id->toBe($payment->id)
            ->paid_amount->toBe('105.0000')->charge_currency->toBe('USD')->charge_amount->toBe('28.5900')->exchange_rate->toBe('0.2723000000')
            ->and($transaction->currency)->toBe('AED')->and($transaction->description)->toBe("Subscription {$invoice->number} (charged USD 28.59 at 0.2723)")
            ->and(ledgerBalance(AccountRef::gateway(PaymentMethod::PayPal)))->toBe('105.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::SubscriptionRevenue)))->toBe('100.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('5.00');
        $this->actingAs($owner)->get(onPanel('partner', 'subscription'))->assertSee('Charged 28.59 USD at 0.2723');
        expect(app(SubscriptionInvoiceDocument::class)->view($invoice)->render())->toContain('28.59 USD');
        assertLedgerBalanced();
    });
});
