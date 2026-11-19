<?php

namespace App\Domains\Orders\Enums;

use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Account → Orders tabs (QC-026, Figma `33:658`).
 */
enum OrderListTab: string
{
    case All = 'all';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returns = 'returns';

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::All => $query,
            self::InProgress => $query->whereIn('status', [
                OrderStatus::Placed->value, OrderStatus::Confirmed->value, OrderStatus::PartiallyShipped->value,
                OrderStatus::Shipped->value, OrderStatus::PartiallyDelivered->value, OrderStatus::Disputed->value,
            ]),
            self::Delivered => $query->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value]),
            self::Cancelled => $query->whereIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]),
            self::Returns => $query->whereHas('returnRequests'),
        };
    }
}
