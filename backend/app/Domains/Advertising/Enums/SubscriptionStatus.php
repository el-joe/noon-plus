<?php

namespace App\Domains\Advertising\Enums;

/**
 * Ad-package subscription (SRS §11.13, lifecycle catalogue §4.9).
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Rejected, self::Cancelled],
            self::Active => [self::Expired, self::Cancelled],
            default => [],
        };
    }
}
