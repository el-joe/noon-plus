<?php

namespace App\Domains\RealEstate\Enums;

/**
 * Exclusive contract (SRS §11.13, lifecycle catalogue §8.4): scheduled →
 * active → expired (daily 00:45) | revoked.
 */
enum ExclusiveStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Scheduled => [self::Active, self::Revoked, self::Expired],
            self::Active => [self::Expired, self::Revoked],
            default => [],
        };
    }
}
