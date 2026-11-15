<?php

namespace App\Domains\Refunds\Enums;

/**
 * Refund lifecycle (SRS §7.5, lifecycle catalogue §2.6):
 * pending → approved | rejected; approved → processing → completed | failed;
 * failed → processing (retry).
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Processing],
            self::Processing => [self::Completed, self::Failed],
            self::Failed => [self::Processing],
            self::Completed, self::Rejected => [],
        };
    }
}
