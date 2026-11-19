<?php

namespace App\Domains\Orders\Support;

use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Support\Collection;

/**
 * Derives the master order status from its sub-orders (SRS §7.2) and lays
 * the `disputed` overlay on top while a dispute is open (QC-013, lifecycle
 * §2.1). Every change writes an order history row with actor `system`.
 */
class OrderStatusRollup
{
    /**
     * Recompute and save the order's status after a sub-order or dispute move.
     */
    public function sync(Order $order, ?string $reason = null): OrderStatus
    {
        $order->loadMissing('subOrders');
        $status = Dispute::query()->where('order_id', $order->id)->whereIn('status', DisputeStatus::openValues())->exists()
            ? OrderStatus::Disputed
            : $this->derive($order->subOrders->pluck('status'));
        $from = $order->status;

        if ($from !== $status) {
            $order->forceFill(['status' => $status])->save();
            StateTransition::query()->create([
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'from_status' => $from?->value,
                'to_status' => $status->value,
                'actor_type' => ActorType::System,
                'reason' => $reason,
            ]);
        }

        return $status;
    }

    /**
     * @param  Collection<int, SubOrderStatus>  $statuses
     */
    public function derive(Collection $statuses): OrderStatus
    {
        $live = $statuses->reject(fn (SubOrderStatus $status): bool => in_array($status, [SubOrderStatus::Cancelled, SubOrderStatus::Refunded], true));

        if ($live->isEmpty()) {
            return $statuses->contains(SubOrderStatus::Refunded) ? OrderStatus::Refunded : OrderStatus::Cancelled;
        }

        $count = fn (array $group): int => $live->filter(fn (SubOrderStatus $status): bool => in_array($status, $group, true))->count();
        $delivered = $count([SubOrderStatus::Delivered, SubOrderStatus::Completed, SubOrderStatus::Returned]);
        $shipped = $count([SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery]) + $delivered;

        return match (true) {
            $live->every(fn (SubOrderStatus $status): bool => $status === SubOrderStatus::Completed) => OrderStatus::Completed,
            $delivered === $live->count() => OrderStatus::Delivered,
            $delivered > 0 => OrderStatus::PartiallyDelivered,
            $shipped === $live->count() => OrderStatus::Shipped,
            $shipped > 0 => OrderStatus::PartiallyShipped,
            $live->every(fn (SubOrderStatus $status): bool => $status !== SubOrderStatus::Placed) => OrderStatus::Confirmed,
            default => OrderStatus::Placed,
        };
    }
}
