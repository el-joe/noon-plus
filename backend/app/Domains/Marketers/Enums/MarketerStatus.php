<?php

namespace App\Domains\Marketers\Enums;

/**
 * Marketer global status (lifecycle catalogue §7.4): pending → active |
 * rejected; active ⇄ suspended.
 */
enum MarketerStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Rejected],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
            self::Rejected => [],
        };
    }
}
