<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Conversion lifecycle (lifecycle catalogue §4.3): pending → approved
 * (03:15) → released (spendable, 03:30); pending/approved/released →
 * reversed; a released conversion whose money was already withdrawn goes
 * to clawback_pending → recovered from the next earnings.
 */
enum ConversionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Released = 'released';
    case Reversed = 'reversed';
    case ClawbackPending = 'clawback_pending';
    case Recovered = 'recovered';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Reversed],
            self::Approved => [self::Released, self::Reversed],
            self::Released => [self::Reversed, self::ClawbackPending],
            self::ClawbackPending => [self::Recovered],
            self::Reversed, self::Recovered => [],
        };
    }
}
