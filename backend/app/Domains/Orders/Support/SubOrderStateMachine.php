<?php

namespace App\Domains\Orders\Support;

use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Finance\Support\CodSettlements;
use App\Domains\Finance\Support\Settlements;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Refunds\Support\CancellationRefunds;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\StateMoved;
use Illuminate\Support\Facades\DB;

/**
 * The only way a sub-order changes status (SRS §7.1). Writes the history with
 * the actor, keeps item fulfilment status in step and re-derives the master
 * order status. Confirming needs a paid (or COD) order. Cancelling puts stock back, returns flash-deal units to the
 * deal, cancels the warranty plans bought with the items and refunds what
 * was paid for the sub-order (SRS §7.3) and reverses marketer conversions
 * of its items (FR-ORD-4). Delivering a COD sub-order records
 * the cash collection; completing it settles the vendor's money (WP4).
 */
class SubOrderStateMachine
{
    public function __construct(
        private OrderStatusRollup $rollup,
        private InventoryLedger $inventoryLedger,
    ) {}

    /**
     * `$codAgentUserId` is the delivery agent who collected the cash when a
     * COD sub-order is delivered (WP7 delivery flow); it names the agent's
     * COD receivable.
     */
    public function transition(SubOrder $subOrder, SubOrderStatus $to, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null, ?int $codAgentUserId = null): SubOrder
    {
        $from = $subOrder->status;

        if (! in_array($to, $from->allowedNext(), true)) {
            throw new SubOrderTransitionRefused("A sub-order cannot move from {$from->value} to {$to->value}.");
        }

        if ($to === SubOrderStatus::Confirmed && ! $this->paymentAllowsConfirmation($subOrder)) {
            throw new SubOrderTransitionRefused('The order can be confirmed once its payment is authorised or captured (or it is cash on delivery).');
        }

        if ($to === SubOrderStatus::Cancelled && $actorType === ActorType::Customer && ! $from->isBeforeShipping()) {
            throw new SubOrderTransitionRefused('Customers can only cancel before the order ships.');
        }

        if ($from === SubOrderStatus::Shipped && $to === SubOrderStatus::Delivered && ! in_array($actorType, [ActorType::Admin, ActorType::System], true)) {
            throw new SubOrderTransitionRefused('A shipped order is delivered through out for delivery; only an admin can confirm it directly.');
        }

        if ($from === SubOrderStatus::Delivered && $to === SubOrderStatus::Cancelled && ($actorType !== ActorType::Admin || blank($reason))) {
            throw new SubOrderTransitionRefused('Only an admin can force-cancel a delivered order, with a reason.');
        }

        if ($to === SubOrderStatus::Cancelled && $actorType === ActorType::Vendor && ! app(SellerFulfilment::class)->canCancel($subOrder)) {
            throw new SubOrderTransitionRefused('Sellers cannot cancel an order that is out for delivery.');
        }

        $moved = DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $from, $to, $actorType, $actorUserId, $reason, $codAgentUserId): SubOrder {
            $subOrder->forceFill([
                'status' => $to,
                'delivered_at' => $to === SubOrderStatus::Delivered ? now() : $subOrder->delivered_at,
            ])->save();

            $subOrder->items()->update(['fulfillment_status' => $to->value]);

            /*
            | FBN: the parcel left the warehouse — its reserved units are gone
            | (whoever handed it over; the sellable count is unchanged).
            */
            if ($to === SubOrderStatus::Shipped) {
                app(WarehouseStockService::class)->dispatchSubOrder($subOrder, $actorUserId);
            }

            /*
            | A delivered parcel is with the customer: a force-cancel refunds
            | it but puts no stock back (a return does that when it arrives).
            */
            if ($to === SubOrderStatus::Cancelled && $from !== SubOrderStatus::Delivered) {
                foreach ($subOrder->items()->get() as $item) {
                    $this->inventoryLedger->move(
                        Listing::query()->findOrFail($item->listing_id),
                        $item->quantity,
                        'order_cancelled',
                        'order_item',
                        $item->id,
                        $actorUserId,
                    );

                    if ($item->flash_sale_submission_id !== null) {
                        FlashSaleSubmission::query()->whereKey($item->flash_sale_submission_id)
                            ->where('sold_quantity', '>=', $item->quantity)
                            ->decrement('sold_quantity', $item->quantity);
                    }
                }
            }

            if ($to === SubOrderStatus::Cancelled) {
                foreach (WarrantyPurchase::query()->whereIn('order_item_id', $subOrder->items()->select('id'))->where('status', WarrantyPurchaseStatus::Active)->get() as $purchase) {
                    app(StateMachine::class)->move($purchase, WarrantyPurchaseStatus::Cancelled, $actorType, $actorUserId, 'Order cancelled');
                }
            }
            $subOrder->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            $this->rollup->sync($subOrder->order()->with('subOrders')->firstOrFail());

            if ($to === SubOrderStatus::Delivered) {
                app(CodSettlements::class)->recordDelivery($subOrder, $codAgentUserId);
            }

            if ($to === SubOrderStatus::Completed) {
                app(Settlements::class)->settle($subOrder);
            }

            return $subOrder;
        });

        if ($to === SubOrderStatus::Cancelled) {
            app(DeliveryDispatch::class)->closeForCancelledSubOrder($moved, $actorType, $actorUserId);

            if ($moved->order()->value('payment_method') === PaymentMethod::Cod->value) {
                app(CodSettlements::class)->syncAfterCancellation($moved->order_id);
            }
            app(CancellationRefunds::class)->forCancelledSubOrder($moved, $actorType, $actorUserId);
            app(Conversions::class)->reverseForSubOrder($moved, 'Sub-order cancelled');
        }

        event(new StateMoved($moved, $from, $to, $actorType, $actorUserId, $reason));

        return $moved;
    }

    /**
     * Lifecycle catalogue §2.2: placed → confirmed needs payment authorised
     * or captured, or cash on delivery.
     */
    private function paymentAllowsConfirmation(SubOrder $subOrder): bool
    {
        $order = $subOrder->order()->firstOrFail();

        return $order->payment_method === PaymentMethod::Cod->value
            || in_array($order->payment_status, [PaymentStatus::Authorized->value, PaymentStatus::Captured->value], true);
    }
}
