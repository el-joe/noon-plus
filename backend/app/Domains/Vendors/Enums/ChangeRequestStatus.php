<?php

namespace App\Domains\Vendors\Enums;

/**
 * Change request for a locked profile section (lifecycle catalogue §7.3,
 * SRS §4.1.5, §12.2): pending → approved (change applied, audit diff) |
 * rejected (reason) | cancelled by the requester.
 */
enum ChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            default => [],
        };
    }
}
