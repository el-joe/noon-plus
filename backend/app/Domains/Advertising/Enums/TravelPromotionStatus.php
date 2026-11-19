<?php

namespace App\Domains\Advertising\Enums;

/**
 * Paid travel promotion (owner decision D14): opened unpaid, live for the
 * paid window once the card payment is captured, then expired. Cancelled
 * when the payment never completes or an admin stops it without money
 * back; refunded when an admin returns the money.
 */
enum TravelPromotionStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Active, self::Cancelled],
            self::Active => [self::Expired, self::Cancelled, self::Refunded],
            self::Expired => [self::Refunded],
            default => [],
        };
    }
}
