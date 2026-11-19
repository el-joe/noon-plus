<?php

namespace App\Domains\Fulfilment\Enums;

/**
 * Warehouse-to-warehouse transfer: requested → in_transit → received;
 * cancelled before it leaves.
 */
enum TransferStatus: string
{
    case Requested = 'requested';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::InTransit, self::Cancelled],
            self::InTransit => [self::Received],
            self::Received, self::Cancelled => [],
        };
    }
}
