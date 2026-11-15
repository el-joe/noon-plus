<?php

use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\AutoCompleteDeliveredOrders;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Refunds\Support\Refunds;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Models\ReturnRequest;
use App\Domains\Returns\Support\ReturnRequests;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * A delivered card order of `$quantity` units with a captured payment.
 *
 * @return array{0: Order, 1: User, 2: string}
 */
function deliveredCardOrder(string $price = '100.00', int $quantity = 2, string $market = 'uae-en', ?Vendor $vendor = null): array
{
    [$order, $customer, $token] = placeOrderVia('stripe', $price, $market, $quantity, vendor: $vendor);
    captureOrderPayment($order);
    moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Delivered);

    return [$order->refresh(), $customer, $token];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function requestReturn(string $token, Order $order, int $quantity = 1, array $overrides = []): TestResponse
{
    $subOrder = $order->subOrders()->first();

    return test()->withToken($token)->post('/api/v1/uae-en/returns', $overrides + [
        'shipment' => $subOrder->number,
        'items' => [['order_item_id' => $subOrder->items()->first()->id, 'quantity' => $quantity]],
        'reason' => 'defective',
        'comment' => 'Stopped working after a day',
        'method' => 'pickup',
        'refund_destination' => 'original',
        'photos' => [UploadedFile::fake()->image('front.jpg')],
    ], ['Accept' => 'application/json']);
}

describe('return requests', function () {
    it('creates a return with photos and shows it to the customer', function () {
        [$order, , $token] = deliveredCardOrder();

        $response = requestReturn($token, $order)->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.paid', ['amount' => '100.00', 'currency' => 'AED']);
        $number = $response->json('data.number');

        expect(ReturnRequest::query()->first()->photos)->toHaveCount(1);
        Storage::disk('local')->assertExists(ReturnRequest::query()->first()->photos[0]);
        Storage::disk('public')->assertMissing(ReturnRequest::query()->first()->photos[0]);
        $photoUrl = $response->json('data.photos.0');
        $this->get($photoUrl)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $photoUrl))->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/uae-en/returns')->assertOk()->assertJsonPath('data.0.number', $number);
        $this->withToken($token)->getJson("/api/v1/uae-en/returns/{$number}")->assertOk()->assertJsonPath('data.timeline.0.status', 'requested');
    });

    it('refuses another customer, undelivered shipments and quantities already returned', function () {
        [$order, , $token] = deliveredCardOrder(quantity: 1);
        [, $otherToken] = customerWithToken();

        requestReturn($otherToken, $order)->assertNotFound();
        app('auth')->forgetGuards();
        requestReturn($token, $order)->assertCreated();
        app('auth')->forgetGuards();
        requestReturn($token, $order)->assertStatus(422)->assertJsonPath('error.code', 'not_allowed');

        [$placed, , $placedToken] = placeOrderVia('cod');
        app('auth')->forgetGuards();
        requestReturn($placedToken, $placed)->assertStatus(422)->assertJsonPath('error.message', 'Only delivered shipments can be returned.');
    });

    it('enforces the return window of each item', function () {
        [$order, , $token] = deliveredCardOrder();
        $order->subOrders()->first()->items()->update(['return_window_days' => 7]);

        $this->travel(8)->days();
        requestReturn($token, $order)->assertStatus(422)->assertJsonPath('error.code', 'not_allowed');
        $this->travel(-2)->days();
        app('auth')->forgetGuards();
        requestReturn($token, $order)->assertCreated();
    });

    it('takes back made-to-order items only for a seller fault', function () {
        [$order, , $token] = deliveredCardOrder();
        $order->subOrders()->first()->items()->first()->listing->variant->product->update(['fulfilment_mode' => 'made_to_order']);

        requestReturn($token, $order, overrides: ['reason' => 'changed_mind'])->assertStatus(422);
        app('auth')->forgetGuards();
        requestReturn($token, $order, overrides: ['reason' => 'defective'])->assertCreated();
    });

    it('lets the customer cancel before pickup only', function () {
        [$order, , $token] = deliveredCardOrder();
        $number = requestReturn($token, $order)->json('data.number');
        $return = ReturnRequest::query()->firstOrFail();
        app(ReturnRequests::class)->approve($return, ActorType::Vendor, 1);
        app(ReturnRequests::class)->advance($return->refresh(), ReturnStatus::AwaitingPickup, ActorType::Admin, 1);
        app(ReturnRequests::class)->advance($return->refresh(), ReturnStatus::InTransit, ActorType::Admin, 1);
        app('auth')->forgetGuards();

        $this->withToken($token)->postJson("/api/v1/uae-en/returns/{$number}/cancel")->assertStatus(422);
    });

    it('keeps the sub-order delivered while a return is open, then auto-completes it', function () {
        [$order, , $token] = deliveredCardOrder();
        $order->subOrders()->first()->items()->update(['return_window_days' => 1]);
        requestReturn($token, $order)->assertCreated();
        $this->travel(3)->days();

        app(AutoCompleteDeliveredOrders::class)->run($this->actAsCountry('uae'));
        expect($order->subOrders()->first()->status)->toBe(SubOrderStatus::Delivered);

        app(ReturnRequests::class)->reject(ReturnRequest::query()->firstOrFail(), ActorType::Vendor, 1, 'Item was used');
        app(AutoCompleteDeliveredOrders::class)->run($this->actAsCountry('uae'));
        expect($order->subOrders()->first()->status)->toBe(SubOrderStatus::Completed);
    });
});

describe('inspection and refunds', function () {
    it('refunds a good item to the card, restocks it and books the ledger', function () {
        [$order, , $token] = deliveredCardOrder('100.00', 2);
        requestReturn($token, $order)->assertCreated();
        $return = ReturnRequest::query()->firstOrFail();
        $listing = $order->subOrders()->first()->items()->first()->listing;
        $stockBefore = app(InventoryLedger::class)->available($listing);
        $returns = app(ReturnRequests::class);
        $returns->approve($return, ActorType::Vendor, 1);
        $returns->advance($return->refresh(), ReturnStatus::AwaitingPickup, ActorType::Admin, 1);
        $returns->advance($return->refresh(), ReturnStatus::InTransit, ActorType::Admin, 1);
        $returns->advance($return->refresh(), ReturnStatus::Received, ActorType::Vendor, 1);

        $done = $returns->inspect($return->refresh(), [$return->items()->first()->id => ['outcome' => 'good', 'restock' => true]], ActorType::Vendor, 1);

        expect($done->status)->toBe(ReturnStatus::Completed)
            ->and($done->refund_total)->toBe('100.0000')
            ->and($done->refund->status)->toBe(RefundStatus::Completed)
            ->and($order->payments()->first()->status)->toBe(PaymentStatus::PartiallyRefunded)
            ->and(FakeGateway::refunds())->toHaveCount(1)
            ->and(app(InventoryLedger::class)->available($listing))->toBe($stockBefore + 1)
            ->and($order->subOrders()->first()->status)->toBe(SubOrderStatus::Delivered)
            ->and(ledgerBalance(AccountRef::gateway(PaymentMethod::Stripe)))->toBe((string) Money::of((string) $order->grand_total, 'AED')->minus(Money::of('100', 'AED'))->getAmount());
        assertLedgerBalanced();
    });

    it('gives a partial refund for customer damage and a full one for transit damage with carrier investigation', function () {
        [$order, , $token] = deliveredCardOrder('80.00', 2);
        requestReturn($token, $order, 2)->assertCreated();
        $return = ReturnRequest::query()->firstOrFail();
        $returns = app(ReturnRequests::class);
        $returns->approve($return, ActorType::Admin, 1);
        $returns->advance($return->refresh(), ReturnStatus::Received, ActorType::Admin, 1);
        $itemId = $return->items()->first()->id;

        expect(fn () => $returns->inspect($return->refresh(), [$itemId => ['outcome' => 'customer_damaged', 'refund_amount' => '160.01']], ActorType::Admin, 1))
            ->toThrow(TransitionRefused::class);

        $done = $returns->inspect($return->refresh(), [$itemId => ['outcome' => 'customer_damaged', 'restock' => false, 'refund_amount' => '40.00']], ActorType::Admin, 1);
        expect($done->refund_total)->toBe('40.0000')->and($done->carrier_investigation)->toBeFalse();

        [$second, , $secondToken] = deliveredCardOrder('80.00', 1);
        app('auth')->forgetGuards();
        requestReturn($secondToken, $second)->assertCreated();
        $transit = ReturnRequest::query()->latest('id')->firstOrFail();
        $returns->approve($transit, ActorType::Admin, 1);
        $returns->advance($transit->refresh(), ReturnStatus::Received, ActorType::Admin, 1);
        $done = $returns->inspect($transit->refresh(), [$transit->items()->first()->id => ['outcome' => 'transit_damaged']], ActorType::Admin, 1);

        expect($done->refund_total)->toBe('80.0000')->and($done->carrier_investigation)->toBeTrue()
            ->and($second->subOrders()->first()->status)->toBe(SubOrderStatus::Refunded);
    });

    it('refunds to Nawy Credits when the customer chose credits', function () {
        [$order, $customer, $token] = deliveredCardOrder('100.00', 1);
        requestReturn($token, $order, overrides: ['refund_destination' => 'wallet'])->assertCreated();
        $return = ReturnRequest::query()->firstOrFail();
        $returns = app(ReturnRequests::class);
        $returns->approve($return, ActorType::Admin, 1);
        $returns->advance($return->refresh(), ReturnStatus::Received, ActorType::Admin, 1);
        $returns->inspect($return->refresh(), [$return->items()->first()->id => ['outcome' => 'good']], ActorType::Admin, 1);

        expect(ledgerBalance(AccountRef::customerWallet($customer->id)))->toBe('100.00')
            ->and(FakeGateway::refunds())->toBe([])
            ->and($order->subOrders()->first()->status)->toBe(SubOrderStatus::Refunded);
        assertLedgerBalanced();
    });

    it('never refunds twice: same key returns the first refund and the total is capped', function () {
        [$order] = deliveredCardOrder('100.00', 1);
        $refunds = app(Refunds::class);
        $subOrder = $order->subOrders()->first();
        $paid = Money::of((string) $order->grand_total, 'AED');

        $first = $refunds->issue($order, $subOrder, Money::of('30.00', 'AED'), RefundSource::Admin, null, RefundDestination::Original, 'admin:1', ActorType::Admin, 1);
        $again = $refunds->issue($order, $subOrder, Money::of('30.00', 'AED'), RefundSource::Admin, null, RefundDestination::Original, 'admin:1', ActorType::Admin, 1);

        expect($again->id)->toBe($first->id)
            ->and(Refund::query()->count())->toBe(1)
            ->and(FakeGateway::refunds())->toHaveCount(1)
            ->and(fn () => $refunds->issue($order, $subOrder, $paid, RefundSource::Admin, null, RefundDestination::Original, 'admin:2', ActorType::Admin, 1))
            ->toThrow(TransitionRefused::class, 'can still be refunded')
            ->and((string) $refunds->refundable($order)->getAmount())->toBe((string) $paid->minus(Money::of('30', 'AED'))->getAmount());
    });

    it('keeps a failed gateway refund for a retry that reuses its share', function () {
        [$order] = deliveredCardOrder('100.00', 1);
        FakeGateway::failNext('refund');
        $refunds = app(Refunds::class);

        $refund = $refunds->issue($order, $order->subOrders()->first(), Money::of('50.00', 'AED'), RefundSource::Admin, null, RefundDestination::Original, 'admin:f', ActorType::Admin, 1);
        expect($refund->status)->toBe(RefundStatus::Failed);

        $retried = $refunds->process($refund->refresh(), ActorType::Admin, 1);
        expect($retried->status)->toBe(RefundStatus::Completed)
            ->and($order->payments()->first()->refunded_amount)->toBe('50.0000');
        assertLedgerBalanced();
    });

    it('refunds a cancelled paid sub-order and a COD order to the wallet', function () {
        [$order] = placeOrderVia('stripe', '60.00');
        captureOrderPayment($order);
        app(SubOrderStateMachine::class)->transition($order->subOrders()->first(), SubOrderStatus::Cancelled, ActorType::Customer, $order->customer_user_id, 'Changed mind');

        expect(Refund::query()->where('source_type', RefundSource::Cancellation)->first())
            ->status->toBe(RefundStatus::Completed)
            ->amount->toBe((string) $order->grand_total);

        [$cod, $codCustomer, $codToken] = placeOrderVia('cod', '50.00');
        moveSubOrderTo($cod->subOrders()->first(), SubOrderStatus::Delivered);
        app('auth')->forgetGuards();
        requestReturn($codToken, $cod, overrides: ['refund_destination' => 'original'])->assertCreated();
        $return = ReturnRequest::query()->latest('id')->firstOrFail();
        app(ReturnRequests::class)->approve($return, ActorType::Admin, 1);
        app(ReturnRequests::class)->advance($return->refresh(), ReturnStatus::Received, ActorType::Admin, 1);
        app(ReturnRequests::class)->inspect($return->refresh(), [$return->items()->first()->id => ['outcome' => 'good']], ActorType::Admin, 1);

        expect($return->refresh()->refund->destination)->toBe(RefundDestination::Wallet)
            ->and(ledgerBalance(AccountRef::customerWallet($codCustomer->id)))->toBe('50.00');
        assertLedgerBalanced();
    });

    it('gives promotional credits back as promotional when a wallet order is refunded', function () {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        giveCredits($customer->id, '40.00');
        app(Wallets::class)->grantPromotional($customer->id, Money::of('100.00', 'AED'), 'promo-w', 'campaign', now()->addDays(10));
        [$order] = placeOrderVia('wallet', '100.00', customer: $customer);

        app(SubOrderStateMachine::class)->transition($order->subOrders()->first(), SubOrderStatus::Cancelled, ActorType::Customer, $customer->id);

        expect(ledgerBalance(AccountRef::customerWallet($customer->id, WalletBucket::Promotional)))->toBe('100.00')
            ->and((string) app(Wallets::class)->available($customer->id, 'AED')->getAmount())->toBe('140.00')
            ->and($order->payments()->first()->status)->toBe(PaymentStatus::Refunded);
        assertLedgerBalanced();
    });
});

describe('panels', function () {
    it('lets the seller review and inspect only their own returns', function () {
        [$user, $vendor] = sellerAccount();
        [$order, , $token] = deliveredCardOrder(vendor: $vendor);
        requestReturn($token, $order)->assertCreated();
        $return = ReturnRequest::query()->firstOrFail();
        [$otherUser] = sellerAccount();
        app('auth')->forgetGuards();

        $this->actingAs($user)->get(onPanel('partner', 'returns'))->assertOk()->assertSee($return->number);
        $this->post(onPanel('partner', "returns/{$return->id}/approve"))->assertRedirect();
        $this->post(onPanel('partner', "returns/{$return->id}/advance"), ['to' => 'awaiting_pickup']);
        $this->post(onPanel('partner', "returns/{$return->id}/advance"), ['to' => 'in_transit']);
        $this->post(onPanel('partner', "returns/{$return->id}/advance"), ['to' => 'received']);
        $this->get(onPanel('partner', "returns/{$return->id}"))->assertOk()->assertSee('Save inspection and refund');
        $this->post(onPanel('partner', "returns/{$return->id}/inspect"), ['items' => [$return->items()->first()->id => ['outcome' => 'good', 'restock' => '1']]])
            ->assertSessionHasNoErrors();

        expect($return->refresh()->status)->toBe(ReturnStatus::Completed);

        $this->actingAs($otherUser)->get(onPanel('partner', "returns/{$return->id}"))->assertNotFound();
    });

    it('needs returns permissions in the admin panel', function () {
        [$order, , $token] = deliveredCardOrder();
        requestReturn($token, $order)->assertCreated();
        $return = ReturnRequest::query()->firstOrFail();
        app('auth')->forgetGuards();

        $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'returns'))->assertForbidden();
        $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', "returns/{$return->id}"))->assertOk();
        $this->post(onPanel('admin', "returns/{$return->id}/approve"))->assertForbidden();
        $this->actingAs(adminAccount('uae', 'operations'))->post(onPanel('admin', "returns/{$return->id}/approve"))->assertRedirect();

        expect($return->refresh()->status)->toBe(ReturnStatus::Approved);
    });
});
