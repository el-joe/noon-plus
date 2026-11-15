<?php

namespace App\Domains\Delivery\Enums;

/**
 * Carrier claim (SRS §4.1.11, lifecycle catalogue §6.4): submitted →
 * under_review → approved | rejected; approved → compensated.
 */
enum ClaimStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Compensated = 'compensated';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::Compensated],
            self::Rejected, self::Compensated => [],
        };
    }
}
