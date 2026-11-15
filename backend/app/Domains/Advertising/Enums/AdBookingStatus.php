<?php

namespace App\Domains\Advertising\Enums;

/**
 * Paid ad booking / Nawi Ads (SRS §10, lifecycle catalogue §4.8).
 */
enum AdBookingStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Cancelled],
            self::PendingReview => [self::Approved, self::Rejected, self::Cancelled, self::Expired],
            self::Approved => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Paused, self::Completed],
            self::Paused => [self::Active, self::Completed],
            default => [],
        };
    }

    /**
     * Statuses that occupy the slot for their dates.
     *
     * @return array<int, self>
     */
    public static function occupying(): array
    {
        return [self::PendingReview, self::Approved, self::Scheduled, self::Active, self::Paused];
    }
}
