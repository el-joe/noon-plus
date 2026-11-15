<?php

namespace App\Domains\Marketers\Enums;

/**
 * Wallet withdrawal (lifecycle catalogue §3.2): pending → approved →
 * processed; pending → rejected.
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processed = 'processed';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Processed, self::Rejected],
            self::Processed, self::Rejected => [],
        };
    }
}
