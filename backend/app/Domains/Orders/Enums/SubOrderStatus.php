<?php

namespace App\Domains\Orders\Enums;

use App\Domains\Orders\Support\SubOrderStateMachine;

/**
 * Sub-order lifecycle (SRS §7.1):
 * placed → confirmed → processing → packed → shipped → out_for_delivery → delivered → completed;
 * cancelled (before shipping for customers); delivered → returned → refunded.
 *
 * Lifecycle catalogue §2.2 notes (QC-034):
 * - shipped → delivered skips out_for_delivery on purpose for parcels that
 *   never get a Nawy agent assignment (third-party carrier hand-over or an
 *   admin confirming delivery). Only admins and the system may take it
 *   ({@see SubOrderStateMachine}); agents
 *   always go through out_for_delivery when they pick the parcel up.
 * - delivered → cancelled is the admin force-cancel ("any → cancelled,
 *   admin (force)"); customers and sellers cannot take it. Completed,
 *   returned and refunded stay closed: money there moves through
 *   returns, disputes and refunds.
 */
enum SubOrderStatus: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Placed => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::Packed, self::Cancelled],
            self::Packed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::OutForDelivery, self::Delivered, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Cancelled],
            self::Delivered => [self::Completed, self::Returned, self::Cancelled],
            self::Returned => [self::Refunded],
            self::Completed, self::Cancelled, self::Refunded => [],
        };
    }

    public function isBeforeShipping(): bool
    {
        return in_array($this, [self::Placed, self::Confirmed, self::Processing, self::Packed], true);
    }
}
