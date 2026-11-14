<?php

namespace App\Domains\Orders\Support;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use Illuminate\Support\Collection;

/**
 * Derives the master order status from its sub-orders (SRS §7.2).
 */
class OrderStatusRollup
{
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
