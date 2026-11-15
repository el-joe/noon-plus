<?php

namespace App\Domains\RealEstate\Enums;

/**
 * Special request (SRS §11.13, lifecycle catalogue §9.1): open → in_progress
 * (one broker claims it atomically) → fulfilled | closed; open → cancelled.
 */
enum SpecialRequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Fulfilled, self::Closed],
            default => [],
        };
    }
}
