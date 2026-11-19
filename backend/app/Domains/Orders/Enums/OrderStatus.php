<?php

namespace App\Domains\Orders\Enums;

/**
 * Master order status, derived from its sub-orders (SRS §7.2, lifecycle
 * §2.1). `disputed` is an overlay (QC-013): while any of the order's
 * disputes is open the order shows `disputed`, and it reverts to the
 * derived status when the last one is resolved or closed.
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
    case Disputed = 'disputed';
}
