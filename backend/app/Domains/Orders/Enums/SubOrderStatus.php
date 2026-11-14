<?php

namespace App\Domains\Orders\Enums;

/**
 * Sub-order lifecycle (SRS §7.1):
 * placed → confirmed → processing → packed → shipped → out_for_delivery → delivered → completed;
 * cancelled (before shipping for customers); delivered → returned → refunded.
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
            self::Delivered => [self::Completed, self::Returned],
            self::Returned => [self::Refunded],
            self::Completed, self::Cancelled, self::Refunded => [],
        };
    }

    public function isBeforeShipping(): bool
    {
        return in_array($this, [self::Placed, self::Confirmed, self::Processing, self::Packed], true);
    }
}
