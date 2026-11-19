<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * Packaging order (lifecycle catalogue §6.3, SRS §4.1.3): placed by a
 * store from the country's packaging catalogue and charged at once on its
 * next statement → admin approves (→ shipped → delivered) or rejects
 * (charge given back).
 */
enum PackagingOrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Shipped],
            self::Shipped => [self::Delivered],
            default => [],
        };
    }
}
