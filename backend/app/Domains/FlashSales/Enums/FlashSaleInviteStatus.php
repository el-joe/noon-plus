<?php

namespace App\Domains\FlashSales\Enums;

/**
 * Flash-sale marketer invite (lifecycle catalogue §4.10 sub-machine):
 * pending → accepted | declined. Accepted marketers earn the event's
 * marketer bonus on flash-price conversions.
 */
enum FlashSaleInviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Declined],
            default => [],
        };
    }
}
