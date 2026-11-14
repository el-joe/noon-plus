<?php

namespace App\Domains\Orders\Enums;

/**
 * Master order status, derived from its sub-orders (SRS §7.2).
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
