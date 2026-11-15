<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Invitation lifecycle (lifecycle catalogue §4.2); `replaced_at` is a flag.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Rejected, self::TimedOut, self::Cancelled],
            self::Accepted => [self::Cancelled],
            default => [],
        };
    }
}
