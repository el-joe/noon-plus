<?php

namespace App\Domains\GiftCards\Enums;

/**
 * Voucher (lifecycle catalogue appendix A): active → used (all redemptions
 * taken) | expired | cancelled.
 */
enum VoucherStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Used, self::Expired, self::Cancelled],
            self::Used, self::Expired, self::Cancelled => [],
        };
    }
}
