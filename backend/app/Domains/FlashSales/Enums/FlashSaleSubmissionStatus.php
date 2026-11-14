<?php

namespace App\Domains\FlashSales\Enums;

/**
 * Seller submission to a flash sale (SRS §10, lifecycle catalogue §4.11):
 * draft → submitted → under_review → approved | rejected; approved → live → sold_out | ended;
 * draft/submitted/under_review/approved → withdrawn.
 */
enum FlashSaleSubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Live = 'live';
    case SoldOut = 'sold_out';
    case Ended = 'ended';
    case Withdrawn = 'withdrawn';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Withdrawn],
            self::UnderReview => [self::Approved, self::Rejected, self::Withdrawn],
            self::Approved => [self::Live, self::Withdrawn],
            self::Live => [self::SoldOut, self::Ended],
            self::Rejected, self::SoldOut, self::Ended, self::Withdrawn => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function pricing(): array
    {
        return [self::Approved, self::Live];
    }
}
