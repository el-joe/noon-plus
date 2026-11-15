<?php

namespace App\Domains\Disputes\Enums;

/**
 * Dispute lifecycle (SRS §7.6, lifecycle catalogue §2.7):
 * open → seller_responded → under_review → escalated → resolved → closed
 * (open may go straight to under_review; under_review may resolve directly).
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case SellerResponded = 'seller_responded';
    case UnderReview = 'under_review';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::SellerResponded, self::UnderReview],
            self::SellerResponded => [self::UnderReview],
            self::UnderReview => [self::Escalated, self::Resolved],
            self::Escalated => [self::Resolved],
            self::Resolved => [self::Closed],
            self::Closed => [],
        };
    }

    /**
     * Not yet decided — blocks auto-complete of the sub-order.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Open->value, self::SellerResponded->value, self::UnderReview->value, self::Escalated->value];
    }
}
