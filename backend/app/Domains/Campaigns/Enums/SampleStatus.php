<?php

namespace App\Domains\Campaigns\Enums;

/**
 * Promotion sample (SRS §11.9, lifecycle catalogue §4.4, states proposed):
 * planned → requested (marketer address) → shipped (stock leaves, sample
 * commission) → delivered → review_due (receipt confirmed, content due) |
 * returned; planned/requested → cancelled.
 */
enum SampleStatus: string
{
    case Planned = 'planned';
    case Requested = 'requested';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case ReviewDue = 'review_due';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Planned => [self::Requested, self::Shipped, self::Cancelled],
            self::Requested => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered => [self::ReviewDue, self::Returned],
            self::ReviewDue => [self::Returned],
            self::Returned, self::Cancelled => [],
        };
    }
}
