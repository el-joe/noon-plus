<?php

use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeResolution;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    Notification::fake();
});

it('QC-013: lays the disputed overlay on the master order while a dispute is open and reverts when it is resolved', function () {
    [, $vendor] = sellerAccount();
    [$order, $customer] = placeOrderVia('card', '100.00', vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Delivered);
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);

    $dispute = app(Disputes::class)->open($subOrder, $customer->id, DisputeReason::cases()[0], 'The item is broken.');
    expect($order->refresh()->status)->toBe(OrderStatus::Disputed);

    // A sub-order move while the dispute is open keeps the overlay.
    moveSubOrderTo($subOrder->refresh(), SubOrderStatus::Completed);
    expect($order->refresh()->status)->toBe(OrderStatus::Disputed);

    $admin = adminAccount();
    app(Disputes::class)->review($dispute, $admin->id);
    expect($order->refresh()->status)->toBe(OrderStatus::Disputed);
    app(Disputes::class)->resolve($dispute->refresh(), $admin->id, DisputeResolution::FavorSeller, note: 'Item works as described');

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and(StateTransition::query()->where('subject_type', 'order')->where('subject_id', $order->id)->pluck('to_status')->all())
        ->toBe(['confirmed', 'shipped', 'delivered', 'disputed', 'completed'])
        ->and(StateTransition::query()->where('subject_type', 'order')->value('actor_type'))->toBe(ActorType::System->value);

    $this->withToken($customer->createToken('t')->plainTextToken)->getJson('/api/v1/uae-en/orders/'.$order->number)->assertOk()->assertJsonPath('data.status', 'completed');
});
