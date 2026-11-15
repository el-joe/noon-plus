<?php

namespace App\Domains\Advertising\Enums;

/**
 * Coupon participation invitation (SRS §10, lifecycle catalogue §4.5).
 */
enum ParticipationInvitationStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Cancelled],
            self::Open => [self::Closed, self::Cancelled],
            self::Closed => [self::Active, self::Ended, self::Cancelled],
            self::Active => [self::Ended],
            default => [],
        };
    }
}
