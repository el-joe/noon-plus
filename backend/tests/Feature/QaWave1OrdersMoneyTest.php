<?php

use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SellerFulfilment;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
    Storage::fake('local');
    app(SettingsService::class)->set('delivery.proof_required_for_cod', false, $this->country);
});

function wave1Agent(): DeliveryAgent
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => test()->country->id]);

    return DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => $user->name, 'status' => 'active']);
}

function wave1CodParcel(): SubOrder
{
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id, 'payment_method' => 'cod'])->save();

    return $subOrder->refresh();
}

it('QA-005: a seller cannot cancel a parcel out for delivery, and a cancelled parcel cannot be delivered', function () {
    $agent = wave1Agent();
    $parcel = wave1CodParcel();
    $assignment = app(DeliveryDispatch::class)->assign($parcel, $agent, ActorType::Admin, 1);
    app(AgentAssignments::class)->accept($assignment, $agent->user_id);
    app(AgentAssignments::class)->start($assignment->refresh(), $agent->user_id);

    expect($parcel->refresh()->status)->toBe(SubOrderStatus::OutForDelivery)
        ->and(app(SellerFulfilment::class)->canCancel($parcel))->toBeFalse();
    expect(fn () => app(SellerFulfilment::class)->cancel($parcel, 'Out of stock', 1))->toThrow(SubOrderTransitionRefused::class);

    app(SubOrderStateMachine::class)->transition($parcel, SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Force cancel');
    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Cancelled)
        ->and(Shipment::query()->where('sub_order_id', $parcel->id)->sole()->status)->toBe(ShipmentStatus::ReturningToOrigin);
    expect(fn () => app(AgentAssignments::class)->confirmDelivery($assignment->refresh(), $agent->user_id, true))->toThrow(TransitionRefused::class);
    expect(CodCollection::query()->count())->toBe(0);
});

it('QA-005: a seller cancel before pickup closes the assignment and cancels the shipment; the agent cannot start it', function () {
    $agent = wave1Agent();
    $parcel = wave1CodParcel();
    $assignment = app(DeliveryDispatch::class)->assign($parcel, $agent, ActorType::Admin, 1);
    app(AgentAssignments::class)->accept($assignment, $agent->user_id);
    $stockBefore = app(InventoryLedger::class)->available($parcel->items()->sole()->listing);

    app(SellerFulfilment::class)->cancel($parcel->refresh(), 'Damaged in stock', 1);

    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Cancelled)
        ->and(Shipment::query()->where('sub_order_id', $parcel->id)->sole()->status)->toBe(ShipmentStatus::Cancelled)
        ->and(app(InventoryLedger::class)->available($parcel->items()->sole()->listing))->toBe($stockBefore + 1);
    expect(fn () => app(AgentAssignments::class)->start($assignment->refresh(), $agent->user_id))->toThrow(TransitionRefused::class);
});

it('QA-006: charges the COD fee on one parcel only, so the parcels add up to the order total', function () {
    $first = wave1CodParcel();
    $order = $first->order;
    $order->forceFill(['cod_fee' => '10.0000', 'grand_total' => '210.0000'])->save();
    $second = $order->subOrders()->create(['vendor_id' => Vendor::factory()->create()->id, 'number' => $order->number.'-2', 'subtotal' => '100.0000', 'total' => '100.0000']);
    $second->forceFill(['status' => SubOrderStatus::Packed])->save();
    $second->items()->create(['listing_id' => $first->items()->sole()->listing_id, 'sku' => 'X', 'title' => 'X', 'quantity' => 1, 'unit_price' => '100.0000', 'line_total' => '100.0000']);

    $agents = [wave1Agent(), wave1Agent()];
    $assignments = [];

    foreach ([$first, $second->refresh()] as $index => $parcel) {
        $assignment = app(DeliveryDispatch::class)->assign($parcel->refresh(), $agents[$index], ActorType::Admin, 1);
        app(AgentAssignments::class)->accept($assignment, $agents[$index]->user_id);
        $assignments[] = $assignment;
    }

    foreach ($assignments as $index => $assignment) {
        app(AgentAssignments::class)->start($assignment->refresh(), $agents[$index]->user_id);
    }

    expect([(string) $assignments[0]->refresh()->cod_amount, (string) $assignments[1]->refresh()->cod_amount])->toBe(['110.0000', '100.0000']);

    foreach (array_reverse($assignments, true) as $index => $assignment) {
        app(AgentAssignments::class)->confirmDelivery($assignment->refresh(), $agents[$index]->user_id, true);
    }

    expect(CodCollection::query()->sum('amount'))->toEqual('210.0000')
        ->and(CodCollection::query()->where('cod_fee', '>', 0)->sole()->sub_order_id)->toBe($first->id);
});

it('QA-007: refuses to suspend a driver with open assignments until they are reassigned', function () {
    $agent = wave1Agent();
    $other = wave1Agent();
    $assignment = app(DeliveryDispatch::class)->assign(wave1CodParcel(), $agent, ActorType::Admin, 1);

    expect(fn () => app(DeliveryAgents::class)->move($agent, AgentStatus::Suspended, ActorType::Carrier, 1))->toThrow(TransitionRefused::class);

    app(DeliveryDispatch::class)->reassign($assignment, $other, ActorType::Admin, 1);
    app(DeliveryAgents::class)->move($agent->refresh(), AgentStatus::Suspended, ActorType::Carrier, 1);
    expect($agent->refresh()->status)->toBe(AgentStatus::Suspended);
});

it('QA-009: a capture for another amount puts the payment under review instead of paying the order', function () {
    [$order] = placeOrderVia('card', '2219.00');
    $payment = $order->payments()->latest('id')->firstOrFail();

    app(PaymentAttempts::class)->applyOutcome($payment, new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, '1.00'), 'webhook');

    expect($payment->refresh()->status)->toBe(PaymentStatus::UnderReview)
        ->and((string) $payment->captured_amount)->toBe('1.0000')
        ->and($payment->failure_code)->toBe('amount_mismatch')
        ->and($order->refresh()->payment_status)->toBe('under_review');

    [$exact] = placeOrderVia('card', '50.00');
    $exactPayment = $exact->payments()->latest('id')->firstOrFail();
    app(PaymentAttempts::class)->applyOutcome($exactPayment, new GatewayOutcome(PaymentStatus::Captured, $exactPayment->gateway_reference, (string) $exactPayment->amount), 'webhook');
    expect($exactPayment->refresh()->status)->toBe(PaymentStatus::Captured);
});

it('QA-016: a fully cancelled COD order voids its pending payment', function () {
    [$order, $customer, $token] = placeOrderVia('cod');
    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$order->subOrders()->sole()->number}/cancel", ['reason' => 'changed_mind'])->assertOk();

    expect($order->payments()->sole()->status)->toBe(PaymentStatus::Voided)
        ->and($order->refresh()->payment_status)->toBe('expired');
});
