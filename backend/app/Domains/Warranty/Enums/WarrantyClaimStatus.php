<?php

namespace App\Domains\Warranty\Enums;

/**
 * Warranty claim (SRS §7.7, lifecycle catalogue §2.8 — states proposed):
 * submitted → under_review → approved | rejected;
 * approved → in_repair | replacement_sent | refund_issued → resolved → closed.
 */
enum WarrantyClaimStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InRepair = 'in_repair';
    case ReplacementSent = 'replacement_sent';
    case RefundIssued = 'refund_issued';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::InRepair, self::ReplacementSent, self::RefundIssued],
            self::InRepair, self::ReplacementSent, self::RefundIssued => [self::Resolved],
            self::Resolved => [self::Closed],
            self::Rejected, self::Closed => [],
        };
    }
}
