<?php

use App\Domains\Disputes\Enums\DisputeResolution;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Domains\GiftCards\Enums\VoucherStatus;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Notifications\GiftCardDelivered;
use App\Domains\GiftCards\Support\GiftCards;
use App\Domains\GiftCards\Support\Vouchers;
use App\Domains\Membership\Enums\MembershipStatus;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Support\AutoCompleteDeliveredOrders;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Enums\WarrantyCoverage;
use App\Domains\Warranty\Enums\WarrantyOutcome;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyClaim;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Domains\Warranty\Support\WarrantyClaims;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Notification::fake();
});

describe('gift cards', function () {
    it('sells a card by card payment, emails the code once paid and never logs it', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        [$buyer, $token] = customerWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', [
            'amount' => '150.00', 'payment_method' => 'card', 'recipient_email' => 'friend@example.com', 'recipient_name' => 'Laila', 'message' => 'Happy birthday',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment.next_action.type', 'redirect');
        expect($response->json('data.masked_code'))->toStartWith('••••-••••-••••-');
        Notification::assertNothingSent();

        $payment = Payment::query()->where('payable_type', GiftCard::PAYABLE)->sole();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        app(PaymentAttempts::class)->refresh($payment);

        $card = GiftCard::query()->sole();
        expect($card->status)->toBe(GiftCardStatus::Active)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::GiftCardLiability)))->toBe('150.00')
            ->and($card->toArray())->not->toHaveKeys(['code_hash', 'code_encrypted']);
        Notification::assertSentOnDemand(GiftCardDelivered::class, fn (GiftCardDelivered $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'friend@example.com' && str_ends_with(str_replace('-', '', $notification->code), $card->code_last4));
        expect(implode("\n", $logged))->not->toContain($card->code_encrypted);
        assertLedgerBalanced();
    });

    it('refuses amounts outside the limits and non-card methods', function () {
        [, $token] = customerWithToken();

        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '10.00', 'payment_method' => 'card', 'recipient_email' => 'a@example.com'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '100.00', 'payment_method' => 'cod', 'recipient_email' => 'a@example.com'])->assertStatus(422);
    });

    it('delivers a scheduled card when its time comes', function () {
        $card = app(GiftCards::class)->issue(1, Money::of('50.00', 'AED'), 'later@example.com');
        Notification::assertSentOnDemandTimes(GiftCardDelivered::class, 1);

        $buyer = User::factory()->create();
        [$scheduled] = app(GiftCards::class)->purchase($buyer, Money::of('80.00', 'AED'), 'card', 'later@example.com', sendAt: now()->addDays(3));
        $payment = Payment::query()->where('payable_id', $scheduled->id)->sole();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        app(PaymentAttempts::class)->refresh($payment);
        expect($scheduled->refresh()->status)->toBe(GiftCardStatus::Scheduled);

        $this->travel(4)->days();
        $this->artisan('nawy:gift-cards:deliver-scheduled')->assertSuccessful();

        expect($scheduled->refresh()->status)->toBe(GiftCardStatus::Active)->and($card->status)->toBe(GiftCardStatus::Active);
        Notification::assertSentOnDemandTimes(GiftCardDelivered::class, 2);
    });

    it('redeems once into spendable credits, only in its own country', function () {
        $code = null;
        Notification::fake();
        app(GiftCards::class)->issue(1, Money::of('75.00', 'AED'), 'me@example.com');
        Notification::assertSentOnDemand(GiftCardDelivered::class, function (GiftCardDelivered $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        [$customer, $token] = customerWithToken();

        $this->withToken($token)->postJson('/api/v1/omn-en/gift-cards/redeem', ['code' => $code])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards/redeem', ['code' => strtolower($code)])->assertOk()
            ->assertJsonPath('data.redeemed', ['amount' => '75.00', 'currency' => 'AED']);
        $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards/redeem', ['code' => $code])->assertStatus(422)
            ->assertJsonPath('error.message', 'This gift card was already redeemed.');

        expect(ledgerBalance(AccountRef::customerWallet($customer->id)))->toBe('75.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::GiftCardLiability)))->toBe('0.00');
        assertLedgerBalanced();
    });

    it('expires unredeemed cards as breakage at 00:30', function () {
        $card = app(GiftCards::class)->issue(1, Money::of('20.00', 'AED'), 'x@example.com');

        $this->travel(13)->months();
        $this->artisan('nawy:gift-cards:expire')->assertSuccessful();

        expect($card->refresh()->status)->toBe(GiftCardStatus::Expired)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BreakageRevenue)))->toBe('20.00');
        assertLedgerBalanced();
    });

    it('redeems a voucher into expiring promotional credits once per customer', function () {
        $voucher = app(Vouchers::class)->create(1, 'WELCOME-2026', 'Welcome', Money::of('15.00', 'AED'), 2, 30, now()->addMonth());
        [$first, $firstToken] = customerWithToken();
        [, $secondToken] = customerWithToken();

        $this->withToken($firstToken)->postJson('/api/v1/uae-en/vouchers/redeem', ['code' => 'welcome2026'])->assertOk()
            ->assertJsonPath('data.promotional.amount', '15.00');
        $this->withToken($firstToken)->postJson('/api/v1/uae-en/vouchers/redeem', ['code' => 'WELCOME-2026'])->assertStatus(422);
        app('auth')->forgetGuards();
        $this->withToken($secondToken)->postJson('/api/v1/uae-en/vouchers/redeem', ['code' => 'WELCOME-2026'])->assertOk();

        expect($voucher->refresh()->status)->toBe(VoucherStatus::Used)
            ->and(ledgerBalance(AccountRef::customerWallet($first->id, WalletBucket::Promotional)))->toBe('15.00');
    });
});

describe('Nawy One', function () {
    beforeEach(function () {
        $this->plan = MembershipPlan::query()->create(['code' => 'one-monthly', 'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly', 'price' => '25.00', 'free_shipping' => true]);
    });

    it('subscribes with credits, gives free delivery and books the revenue', function () {
        [$customer, $token] = customerWithToken();
        giveCredits($customer->id, '30.00');
        $listing = sellableListing('40.00');
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
        $before = $this->withToken($token)->getJson('/api/v1/uae-en/cart')->json('data.totals.shipping.amount');

        $this->withToken($token)->postJson('/api/v1/uae-en/membership', ['plan' => 'one-monthly', 'payment_method' => 'wallet'])->assertCreated()
            ->assertJsonPath('data.member', true)
            ->assertJsonPath('data.benefits.free_shipping', true);

        expect($before)->not->toBe('0.00')
            ->and($this->withToken($token)->getJson('/api/v1/uae-en/cart')->json('data.totals.shipping.amount'))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::MembershipRevenue)))->toBe('23.81')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('1.19');
        assertLedgerBalanced();
    });

    it('renews from credits, goes past due without them and expires after the grace days', function () {
        [$customer, $token] = customerWithToken();
        giveCredits($customer->id, '50.00');
        $this->withToken($token)->postJson('/api/v1/uae-en/membership', ['plan' => 'one-monthly', 'payment_method' => 'wallet'])->assertCreated();
        $membership = Membership::query()->sole();

        $this->travel(32)->days();
        $this->artisan('nawy:membership:renew')->assertSuccessful();
        expect($membership->refresh())->status->toBe(MembershipStatus::Active)
            ->and($membership->invoices()->where('status', 'paid')->count())->toBe(2);

        $this->travel(31)->days();
        $this->artisan('nawy:membership:renew')->assertSuccessful();
        expect($membership->refresh()->status)->toBe(MembershipStatus::PastDue);

        $this->travel(8)->days();
        $this->artisan('nawy:membership:renew')->assertSuccessful();
        expect($membership->refresh()->status)->toBe(MembershipStatus::Expired)->and($membership->givesBenefits())->toBeFalse();
    });

    it('stops at the period end after cancelling, and starts a first-time trial', function () {
        [$customer, $token] = customerWithToken();
        giveCredits($customer->id, '25.00');
        $this->withToken($token)->postJson('/api/v1/uae-en/membership', ['plan' => 'one-monthly', 'payment_method' => 'wallet'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/uae-en/membership/cancel')->assertOk()->assertJsonPath('data.auto_renew', false);

        $this->travel(32)->days();
        $this->artisan('nawy:membership:renew')->assertSuccessful();
        expect(Membership::query()->sole()->status)->toBe(MembershipStatus::Cancelled);

        $this->plan->update(['trial_days' => 14]);
        [, $trialToken] = customerWithToken();
        app('auth')->forgetGuards();
        $this->withToken($trialToken)->postJson('/api/v1/uae-en/membership', ['plan' => 'one-monthly', 'payment_method' => 'card'])->assertCreated()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.payment', null);
    });
});

describe('disputes', function () {
    it('opens, gets a seller reply, is reviewed and resolved with a refund, and blocks auto-complete meanwhile', function () {
        [$order, $customer, $token] = placeOrderVia('stripe', '100.00');
        captureOrderPayment($order);
        $subOrder = moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Delivered);
        $subOrder->items()->update(['return_window_days' => 1]);

        $number = $this->withToken($token)->postJson('/api/v1/uae-en/disputes', ['shipment' => $subOrder->number, 'reason' => 'not_as_described', 'message' => 'The colour is completely different.'])
            ->assertCreated()->json('data.number');
        $this->withToken($token)->postJson('/api/v1/uae-en/disputes', ['shipment' => $subOrder->number, 'reason' => 'other', 'message' => 'Opening a second one now.'])->assertStatus(422);

        $dispute = Dispute::query()->sole();
        $disputes = app(Disputes::class);
        $disputes->message($dispute, ActorType::Vendor, 5, 'We can exchange it.');
        expect($dispute->refresh()->status)->toBe(DisputeStatus::SellerResponded);

        $this->travel(3)->days();
        app(AutoCompleteDeliveredOrders::class)->run($this->country);
        expect($subOrder->refresh()->status)->toBe(SubOrderStatus::Delivered);

        expect(fn () => $disputes->resolve($dispute->refresh(), 1, DisputeResolution::Split))->toThrow(TransitionRefused::class);
        $disputes->review($dispute->refresh(), 1);
        $disputes->resolve($dispute->refresh(), 1, DisputeResolution::Split, Money::of('30.00', 'AED'));
        $disputes->close($dispute->refresh(), ActorType::Admin, 1);

        expect($dispute->refresh())->status->toBe(DisputeStatus::Closed)->refund_amount->toBe('30.0000')
            ->and($order->payments()->first()->refunded_amount)->toBe('30.0000');
        $this->withToken($token)->getJson("/api/v1/uae-en/disputes/{$number}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->withToken($token)->postJson("/api/v1/uae-en/disputes/{$number}/messages", ['body' => 'Thanks'])->assertStatus(422);

        app(AutoCompleteDeliveredOrders::class)->run($this->country);
        expect($subOrder->refresh()->status)->toBe(SubOrderStatus::Completed);
        assertLedgerBalanced();
    });
});

describe('warranty claims', function () {
    it('routes a claim with an active extended plan to Nawy and refunds one unit from the platform', function () {
        [$order, , $token] = placeOrderVia('stripe', '300.00');
        captureOrderPayment($order);
        $subOrder = moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Completed);
        $item = $subOrder->items()->first();
        WarrantyPurchase::query()->create([
            'order_id' => $order->id, 'order_item_id' => $item->id, 'warranty_plan_id' => WarrantyPlan::factory()->create()->id,
            'customer_user_id' => $order->customer_user_id, 'status' => WarrantyPurchaseStatus::Active, 'quantity' => 1, 'unit_price' => '20', 'total' => '20',
            'duration_months' => 24, 'starts_at' => now(), 'expires_at' => now()->addMonths(24),
        ]);

        $number = $this->withToken($token)->postJson('/api/v1/uae-en/warranty-claims', ['order_item_id' => $item->id, 'description' => 'Screen flickers since last week.'])
            ->assertCreated()->assertJsonPath('data.coverage', 'extended')->json('data.number');
        $claim = WarrantyClaim::query()->sole();
        $claims = app(WarrantyClaims::class);

        expect(fn () => $claims->review($claim, ActorType::Vendor, 5))->toThrow(TransitionRefused::class);
        $claims->message($claim, ActorType::Vendor, 5, 'Happy to help Nawy with details.');
        $claims->review($claim, ActorType::Admin, 1);
        $claims->approve($claim->refresh(), ActorType::Admin, 1);
        $claims->fulfil($claim->refresh(), WarrantyOutcome::Refund, ActorType::Admin, 1);

        expect($claim->refresh()->status)->toBe(WarrantyClaimStatus::RefundIssued)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::WarrantyClaimsExpense)))->toBe('300.00');
        $this->withToken($token)->getJson("/api/v1/uae-en/warranty-claims/{$number}")->assertOk()->assertJsonCount(2, 'data.messages');
        assertLedgerBalanced();
    });

    it('uses the standard warranty within its months and refuses after', function () {
        [$order, , $token] = placeOrderVia('cod', '100.00');
        $subOrder = moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Delivered);
        $item = $subOrder->items()->first();

        $this->withToken($token)->postJson('/api/v1/uae-en/warranty-claims', ['order_item_id' => $item->id, 'description' => 'Does not turn on anymore.'])
            ->assertCreated()->assertJsonPath('data.coverage', WarrantyCoverage::Standard->value);
        app(WarrantyClaims::class)->review(WarrantyClaim::query()->sole(), ActorType::Vendor, 5);
        app(WarrantyClaims::class)->reject(WarrantyClaim::query()->sole(), ActorType::Vendor, 5, 'Water damage');

        $this->travel(13)->months();
        $this->withToken($token)->postJson('/api/v1/uae-en/warranty-claims', ['order_item_id' => $item->id, 'description' => 'Does not turn on anymore.'])->assertStatus(422);
    });

    it('expires extended plans daily', function () {
        [$order] = placeOrderVia('cod');
        $purchase = WarrantyPurchase::query()->create([
            'order_id' => $order->id, 'order_item_id' => $order->subOrders()->first()->items()->first()->id,
            'warranty_plan_id' => WarrantyPlan::factory()->create()->id, 'customer_user_id' => $order->customer_user_id,
            'status' => WarrantyPurchaseStatus::Active, 'quantity' => 1, 'unit_price' => '20', 'total' => '20', 'duration_months' => 1,
            'starts_at' => now(), 'expires_at' => now()->addMonth(),
        ]);

        $this->travel(2)->months();
        $this->artisan('nawy:warranty:expire')->assertSuccessful();

        expect($purchase->refresh()->status)->toBe(WarrantyPurchaseStatus::Expired);
    });
});
