<?php

namespace App\Domains\Finance\Enums;

/**
 * Vendor payout (SRS §9, lifecycle catalogue §3.1):
 * pending → approved → processing → completed | failed; pending ⇄ on_hold;
 * failed → processing (retry). Recalculate keeps it pending.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::OnHold, self::Pending],
            self::OnHold => [self::Pending],
            self::Approved => [self::Processing],
            self::Processing => [self::Completed, self::Failed],
            self::Failed => [self::Processing],
            self::Completed => [],
        };
    }
}
