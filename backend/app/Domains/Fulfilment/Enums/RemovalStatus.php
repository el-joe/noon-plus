<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * FBN removal order (stock back to the seller or disposal):
 * requested → processing (picked, units leave the stock) → completed (shipped
 * or disposed, removal fee charged); requested → cancelled (seller) or
 * rejected (warehouse), which puts held units back on sale.
 */
enum RemovalStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Processing, self::Cancelled, self::Rejected],
            self::Processing => [self::Completed],
            self::Completed, self::Cancelled, self::Rejected => [],
        };
    }
}
