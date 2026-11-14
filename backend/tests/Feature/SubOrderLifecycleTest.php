<?php

use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\SubOrderStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * Places a two-seller order and returns it.
 *
 * @return array{0: Order, 1: string, 2: Listing}
 */
function twoSellerOrder(): array
{
    [, $token] = customerWithToken();
    $first = sellableListing('100.00', stock: 3);
    $second = sellableListing('50.00', stock: 3);
    test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $first->id, 'quantity' => 2]);
    test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $second->id, 'quantity' => 1]);
    $number = test()->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'cod', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])->json('data.number');

    return [Order::query()->where('number', $number)->sole(), $token, $first];
}

it('rolls the order status up from its shipments and records each step', function () {
    [$order] = twoSellerOrder();
    [$first, $second] = $order->subOrders->all();
    $machine = app(SubOrderStateMachine::class);

    foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $status) {
        $machine->transition($first, $status, ActorType::Vendor, 9);
    }
    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyShipped);

    $machine->transition($first, SubOrderStatus::Delivered, ActorType::System);
    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyDelivered)
        ->and($first->fresh()->delivered_at)->not->toBeNull()
        ->and($first->statusHistory()->count())->toBe(6);

    $machine->transition($second, SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Seller could not fulfil');
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('lets a customer cancel a shipment before it ships and puts the stock back', function () {
    [$order, $token, $first] = twoSellerOrder();
    $shipment = $order->subOrders->first();

    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$shipment->number}/cancel", ['reason' => 'Changed my mind'])
        ->assertOk()
        ->assertJsonPath('data.shipments.0.status', 'cancelled');

    expect(app(InventoryLedger::class)->available($first))->toBe(3);
});

it('does not let a customer cancel once the shipment has shipped', function () {
    [$order, $token] = twoSellerOrder();
    $shipment = $order->subOrders->first();
    $machine = app(SubOrderStateMachine::class);
    foreach ([SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped] as $status) {
        $machine->transition($shipment, $status, ActorType::Vendor);
    }

    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$shipment->number}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cannot_cancel');
});

it('refuses skipping steps in the lifecycle', function () {
    [$order] = twoSellerOrder();

    app(SubOrderStateMachine::class)->transition($order->subOrders->first(), SubOrderStatus::Delivered, ActorType::Vendor);
})->throws(SubOrderTransitionRefused::class);

it('marks the order cancelled when every shipment is cancelled', function () {
    [$order] = twoSellerOrder();
    $machine = app(SubOrderStateMachine::class);

    foreach ($order->subOrders as $shipment) {
        $machine->transition($shipment, SubOrderStatus::Cancelled, ActorType::Admin, 1);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
