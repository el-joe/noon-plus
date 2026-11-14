<?php

namespace App\Domains\Orders\Support;

use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPurchase;
use Illuminate\Support\Facades\DB;

/**
 * The only way a sub-order changes status (SRS §7.1). Writes the history with
 * the actor, keeps item fulfilment status in step and re-derives the master
 * order status. Cancelling puts stock back, returns flash-deal units to the
 * deal and cancels the warranty plans bought with the items.
 */
class SubOrderStateMachine
{
    public function __construct(
        private OrderStatusRollup $rollup,
        private InventoryLedger $inventoryLedger,
    ) {}

    public function transition(SubOrder $subOrder, SubOrderStatus $to, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): SubOrder
    {
        $from = $subOrder->status;

        if (! in_array($to, $from->allowedNext(), true)) {
            throw new SubOrderTransitionRefused("A sub-order cannot move from {$from->value} to {$to->value}.");
        }

        if ($to === SubOrderStatus::Cancelled && $actorType === ActorType::Customer && ! $from->isBeforeShipping()) {
            throw new SubOrderTransitionRefused('Customers can only cancel before the order ships.');
        }

        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder, $from, $to, $actorType, $actorUserId, $reason): SubOrder {
            $subOrder->forceFill([
                'status' => $to,
                'delivered_at' => $to === SubOrderStatus::Delivered ? now() : $subOrder->delivered_at,
            ])->save();

            $subOrder->items()->update(['fulfillment_status' => $to->value]);

            if ($to === SubOrderStatus::Cancelled) {
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

                WarrantyPurchase::query()
                    ->whereIn('order_item_id', $subOrder->items()->select('id'))
                    ->where('status', WarrantyPurchaseStatus::Active)
                    ->update(['status' => WarrantyPurchaseStatus::Cancelled]);
            }
            $subOrder->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            $order = $subOrder->order()->with('subOrders')->firstOrFail();
            $order->forceFill(['status' => $this->rollup->derive($order->subOrders->pluck('status'))])->save();

            return $subOrder;
        });
    }
}
