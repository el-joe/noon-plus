<?php

namespace App\Domains\Finance\Enums;

/**
 * COD settlement (SRS §8, lifecycle catalogue §3.4): pending → settled,
 * pending → disputed (remitted cash differs) → pending | settled.
 */
enum CodSettlementStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Disputed = 'disputed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Settled, self::Disputed],
            self::Disputed => [self::Pending, self::Settled],
            self::Settled => [],
        };
    }
}
