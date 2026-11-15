<?php

namespace App\Domains\GiftCards\Enums;

/**
 * Gift card (lifecycle catalogue §4.12, states proposed): pending_payment →
 * scheduled (email later) | active; scheduled → active; active → redeemed
 * (into Nawy Credits) | expired (daily 00:30); cancelled by admin or when
 * never paid.
 */
enum GiftCardStatus: string
{
    case PendingPayment = 'pending_payment';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Redeemed, self::Expired, self::Cancelled],
            self::Redeemed, self::Expired, self::Cancelled => [],
        };
    }
}
