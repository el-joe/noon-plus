<?php

namespace App\Domains\RealEstate\Enums;

/**
 * Property viewing request: requested → confirmed → completed | no_show; requested/confirmed → cancelled.
 */
enum ViewingStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::NoShow, self::Cancelled],
            default => [],
        };
    }
}
