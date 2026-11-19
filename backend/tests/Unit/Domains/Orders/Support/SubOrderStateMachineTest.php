<?php

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use Illuminate\Foundation\Testing\TestCase;

/*
| The guards that refuse a move before anything is read or written. The
| sub-order is never saved: without a country selected, any database access
| would fail with another exception than the refusal asserted here.
*/

uses(TestCase::class);

function unsavedSubOrder(SubOrderStatus $status): SubOrder
{
    return (new SubOrder)->forceFill(['status' => $status]);
}

it('refuses a move the lifecycle does not allow', function (SubOrderStatus $from, SubOrderStatus $to) {
    app(SubOrderStateMachine::class)->transition(unsavedSubOrder($from), $to, ActorType::Admin, 1, 'test');
})->with([
    'placed cannot skip to shipped' => [SubOrderStatus::Placed, SubOrderStatus::Shipped],
    'completed cannot be cancelled' => [SubOrderStatus::Completed, SubOrderStatus::Cancelled],
    'refunded cannot be reopened' => [SubOrderStatus::Refunded, SubOrderStatus::Placed],
    'packed cannot go back to processing' => [SubOrderStatus::Packed, SubOrderStatus::Processing],
])->throws(SubOrderTransitionRefused::class, 'A sub-order cannot move from');

it('refuses a customer cancellation once the order has shipped', function (SubOrderStatus $from) {
    app(SubOrderStateMachine::class)->transition(unsavedSubOrder($from), SubOrderStatus::Cancelled, ActorType::Customer, 1);
})->with([SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery, SubOrderStatus::Delivered])
    ->throws(SubOrderTransitionRefused::class, 'Customers can only cancel before the order ships.');

it('refuses a direct shipped to delivered move by anyone but an admin or the system', function (ActorType $actor) {
    app(SubOrderStateMachine::class)->transition(unsavedSubOrder(SubOrderStatus::Shipped), SubOrderStatus::Delivered, $actor, 1);
})->with([ActorType::Agent, ActorType::Vendor, ActorType::Customer, ActorType::Carrier])
    ->throws(SubOrderTransitionRefused::class, 'only an admin can confirm it directly');

it('refuses a force-cancel of a delivered order without an admin and a reason', function (ActorType $actor, ?string $reason) {
    app(SubOrderStateMachine::class)->transition(unsavedSubOrder(SubOrderStatus::Delivered), SubOrderStatus::Cancelled, $actor, 1, $reason);
})->with([
    'admin without a reason' => [ActorType::Admin, null],
    'admin with a blank reason' => [ActorType::Admin, '   '],
    'vendor with a reason' => [ActorType::Vendor, 'Customer asked'],
    'system with a reason' => [ActorType::System, 'Timeout'],
])->throws(SubOrderTransitionRefused::class, 'Only an admin can force-cancel a delivered order, with a reason.');
