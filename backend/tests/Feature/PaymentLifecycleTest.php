<?php

use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Enums\RefundStatus;
use App\Domains\Payments\Exceptions\PaymentTransitionRefused;
use App\Domains\Payments\Exceptions\RefundRefused;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentRefunds;
use App\Domains\Payments\Support\PaymentStateMachine;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * Place an order in the current market with one listing.
 */
function orderPaidWith(string $method, string $price = '100.00', int $stock = 3, string $market = 'uae-en'): Payment
{
    [, $token] = customerWithToken();
    $listing = sellableListing($price, stock: $stock);
    test()->withToken($token)->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => 1]);
    test()->withToken($token)->postJson("/api/v1/{$market}/orders", ['payment_method' => $method, 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])->assertCreated();
    app('auth')->forgetGuards();

    return Payment::query()->latest('id')->firstOrFail();
}

function capture(Payment $payment): Payment
{
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);

    return app(PaymentAttempts::class)->refresh($payment);
}

describe('status machine', function () {
    it('allows only the documented moves', function (PaymentStatus $from, array $allowed) {
        expect($from->allowedNext())->toBe($allowed);
    })->with([
        [PaymentStatus::Pending, [PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::Failed, PaymentStatus::Expired, PaymentStatus::UnderReview, PaymentStatus::Voided]],
        [PaymentStatus::Authorized, [PaymentStatus::Captured, PaymentStatus::Failed, PaymentStatus::Refunded, PaymentStatus::UnderReview]],
        [PaymentStatus::UnderReview, [PaymentStatus::Captured, PaymentStatus::Failed, PaymentStatus::Refunded]],
        [PaymentStatus::Voided, []],
        [PaymentStatus::Captured, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded]],
        [PaymentStatus::PartiallyRefunded, [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded]],
        [PaymentStatus::Failed, []],
        [PaymentStatus::Expired, []],
        [PaymentStatus::Refunded, []],
    ]);

    it('writes history with actor and source and keeps the order payment status in step', function () {
        $payment = orderPaidWith('stripe');
        $machine = app(PaymentStateMachine::class);

        $machine->transition($payment, PaymentStatus::Authorized, ActorType::System, source: 'webhook');
        $machine->transition($payment, PaymentStatus::Captured, ActorType::System, source: 'webhook');

        $last = $payment->statusHistory()->get()->last();
        expect($last)->from_status->toBe(PaymentStatus::Authorized)->to_status->toBe(PaymentStatus::Captured)->actor_type->toBe(ActorType::System)->source->toBe('webhook')
            ->and($payment->fresh()->captured_at)->not->toBeNull()
            ->and($payment->order->fresh()->payment_status)->toBe('captured');
    });

    it('refuses backward moves', function () {
        $payment = capture(orderPaidWith('stripe'));

        app(PaymentStateMachine::class)->transition($payment, PaymentStatus::Pending, ActorType::Admin, 1);
    })->throws(PaymentTransitionRefused::class);

    it('derives the order payment status from the attempts', function () {
        $payment = orderPaidWith('stripe');
        $order = $payment->order;
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
        app(PaymentAttempts::class)->refresh($payment);
        expect($order->fresh()->payment_status)->toBe('failed');

        $retry = app(PaymentAttempts::class)->retry($order->fresh(), 'paytabs', User::query()->find($order->customer_user_id));
        expect($order->fresh()->payment_status)->toBe('pending');

        capture($retry);
        expect($order->fresh()->payment_status)->toBe('captured');
    });
});

describe('pending payment expiry', function () {
    it('cancels unpaid orders after the hold window and puts the stock back', function () {
        $payment = orderPaidWith('stripe', stock: 2);
        $listing = $payment->order->subOrders()->sole()->items()->sole()->listing;
        expect(app(InventoryLedger::class)->available($listing))->toBe(1);

        $this->travel(29)->minutes();
        $this->artisan('nawy:payments:expire-pending')->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

        $this->travel(2)->minutes();
        $this->artisan('nawy:payments:expire-pending')->assertSuccessful();

        $order = $payment->order->fresh();
        $subOrder = $order->subOrders()->sole();
        expect($payment->fresh())->status->toBe(PaymentStatus::Expired)
            ->and($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment_status)->toBe('expired')
            ->and($subOrder->status)->toBe(SubOrderStatus::Cancelled)
            ->and($subOrder->statusHistory()->get()->last())->actor_type->toBe(ActorType::System)->reason->toBe('Payment not completed in time')
            ->and(app(InventoryLedger::class)->available($listing))->toBe(2);
    });

    it('also cancels an order whose last attempt failed', function () {
        $payment = orderPaidWith('stripe');
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
        app(PaymentAttempts::class)->refresh($payment);

        $this->travel(31)->minutes();
        $this->artisan('nawy:payments:expire-pending');

        expect($payment->order->fresh())->status->toBe(OrderStatus::Cancelled)->payment_status->toBe('expired');
    });

    it('keeps an order the gateway reports as paid at the last check', function () {
        $payment = orderPaidWith('stripe');
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);

        $this->travel(31)->minutes();
        $this->artisan('nawy:payments:expire-pending');

        expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
            ->and($payment->order->fresh())->status->toBe(OrderStatus::Placed)->payment_status->toBe('captured');
    });

    it('never expires COD orders and runs in every country', function () {
        $cod = orderPaidWith('cod');
        $this->actAsCountry('omn');
        $omani = orderPaidWith('thawani', '10.500', market: 'omn-en');
        $this->actAsCountry('uae');

        $this->travel(2)->hours();
        $this->artisan('nawy:payments:expire-pending')->assertSuccessful();

        expect($cod->order->fresh()->status)->toBe(OrderStatus::Placed);
        $this->actAsCountry('omn');
        expect(Order::query()->findOrFail($omani->order_id)->status)->toBe(OrderStatus::Cancelled);
    });

    it('ignores a capture that arrives after the payment expired', function () {
        $payment = orderPaidWith('stripe');
        $this->travel(31)->minutes();
        $this->artisan('nawy:payments:expire-pending');

        app(PaymentAttempts::class)->applyOutcome($payment, new GatewayOutcome(PaymentStatus::Captured), 'webhook');

        expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
    });
});

describe('refunds', function () {
    it('refunds partially then fully and moves the payment status', function () {
        $payment = capture(orderPaidWith('stripe'));
        $refunds = app(PaymentRefunds::class);

        $first = $refunds->refund($payment, Money::of('30.00', 'AED'), 'return:1', ActorType::Admin, 1, 'Damaged item');
        expect($first->status)->toBe(RefundStatus::Completed)
            ->and($payment->fresh())->status->toBe(PaymentStatus::PartiallyRefunded)->refunded_amount->toBe('30.0000')
            ->and($payment->order->fresh()->payment_status)->toBe('partially_refunded');

        $refunds->refund($payment, Money::of('70.00', 'AED'), 'return:2', ActorType::System);
        expect($payment->fresh())->status->toBe(PaymentStatus::Refunded)->refunded_amount->toBe('100.0000')
            ->and($payment->order->fresh()->payment_status)->toBe('refunded')
            ->and(array_column(FakeGateway::refunds(), 'amount'))->toBe(['30.00', '70.00']);
    });

    it('is idempotent per key', function () {
        $payment = capture(orderPaidWith('stripe'));
        $refunds = app(PaymentRefunds::class);

        $first = $refunds->refund($payment, Money::of('40.00', 'AED'), 'cancel:7', ActorType::System);
        $again = $refunds->refund($payment, Money::of('40.00', 'AED'), 'cancel:7', ActorType::System);

        expect($again->id)->toBe($first->id)
            ->and(FakeGateway::refunds())->toHaveCount(1)
            ->and($payment->fresh()->refunded_amount)->toBe('40.0000');

        expect(fn () => $refunds->refund($payment, Money::of('10.00', 'AED'), 'cancel:7', ActorType::System))->toThrow(RefundRefused::class, 'already used');
    });

    it('never refunds more than was captured', function () {
        $payment = capture(orderPaidWith('stripe'));
        $refunds = app(PaymentRefunds::class);
        $refunds->refund($payment, Money::of('80.00', 'AED'), 'r1', ActorType::System);

        expect(fn () => $refunds->refund($payment, Money::of('20.01', 'AED'), 'r2', ActorType::System))->toThrow(RefundRefused::class, '20.00')
            ->and(fn () => $refunds->refund($payment, Money::of('0', 'AED'), 'r3', ActorType::System))->toThrow(RefundRefused::class)
            ->and(fn () => $refunds->refund($payment, Money::of('5.000', 'OMR'), 'r4', ActorType::System))->toThrow(RefundRefused::class);
    });

    it('refuses uncaptured payments and records gateway failures', function () {
        $payment = orderPaidWith('stripe');
        $refunds = app(PaymentRefunds::class);

        expect(fn () => $refunds->refund($payment, Money::of('10.00', 'AED'), 'r1', ActorType::System))->toThrow(RefundRefused::class, 'captured');

        capture($payment);
        FakeGateway::failNext('refund');
        expect(fn () => $refunds->refund($payment, Money::of('10.00', 'AED'), 'r2', ActorType::System))->toThrow(RefundRefused::class);
        expect($payment->refunds()->sole()->status)->toBe(RefundStatus::Failed)
            ->and($payment->fresh())->status->toBe(PaymentStatus::Captured)->refunded_amount->toBe('0.0000');

        $refunds->refund($payment, Money::of('100.00', 'AED'), 'r3', ActorType::System);
        expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
    });

    it('refunds an order through its captured payment in a 3-decimal currency', function () {
        $this->actAsCountry('omn');
        $payment = capture(orderPaidWith('thawani', '12.345', market: 'omn-en'));

        app(PaymentRefunds::class)->refundOrder($payment->order, Money::of('2.005', 'OMR'), 'omn-r1', ActorType::Admin, 1);

        expect($payment->fresh())->status->toBe(PaymentStatus::PartiallyRefunded)->refunded_amount->toBe('2.0050');
    });

    it('records a COD refund without a gateway call (the refund service books it to the wallet)', function () {
        $payment = orderPaidWith('cod');
        app(PaymentStateMachine::class)->transition($payment, PaymentStatus::Captured, ActorType::System, attributes: ['captured_amount' => $payment->amount]);

        $refund = app(PaymentRefunds::class)->refund($payment->fresh(), Money::of('10.00', 'AED'), 'cod-1', ActorType::System);

        expect($refund->gateway_reference)->toBe('wallet_cod-1')
            ->and(FakeGateway::refunds())->toBe([])
            ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
    });
});
