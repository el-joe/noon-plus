<?php

namespace App\Domains\FlashSales\Enums;

/**
 * Flash-sale event lifecycle (SRS §10, lifecycle catalogue §4.10):
 * draft → submission_open → submission_closed → under_review → approved → live → ended;
 * any pre-live state → cancelled.
 */
enum FlashSaleEventStatus: string
{
    case Draft = 'draft';
    case SubmissionOpen = 'submission_open';
    case SubmissionClosed = 'submission_closed';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Live = 'live';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::SubmissionOpen, self::Cancelled],
            self::SubmissionOpen => [self::SubmissionClosed, self::Cancelled],
            self::SubmissionClosed => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::Approved, self::Cancelled],
            self::Approved => [self::Live, self::Cancelled],
            self::Live => [self::Ended],
            self::Ended, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Statuses in which flash prices apply inside the event window. Approved
     * counts too, so prices start on time even before the 5-minute job runs.
     *
     * @return array<int, self>
     */
    public static function pricing(): array
    {
        return [self::Approved, self::Live];
    }
}
