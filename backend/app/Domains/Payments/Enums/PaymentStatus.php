<?php

namespace App\Domains\Payments\Enums;

/**
 * Order payment lifecycle (SRS FR-CHK-3, lifecycle catalogue appendix A):
 * pending → authorized → captured → partially_refunded → refunded;
 * pending → failed | expired. A capture for another amount than the
 * attempt goes to under_review (QA-009): never paid, an admin captures it
 * (accepts), refunds it or fails it after reconciliation. A COD payment of
 * an order cancelled before anything was collected is voided (QA-016). Backward moves are refused, so late or
 * duplicated gateway notifications can never undo a payment (risk T9).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case UnderReview = 'under_review';
    case Voided = 'voided';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Authorized, self::Captured, self::Failed, self::Expired, self::UnderReview, self::Voided],
            self::Authorized => [self::Captured, self::Failed, self::Refunded, self::UnderReview],
            self::UnderReview => [self::Captured, self::Failed, self::Refunded],
            self::Captured => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Failed, self::Expired, self::Refunded, self::Voided => [],
        };
    }

    /**
     * The customer's money reached (or is held for) Nawy.
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::Authorized, self::Captured, self::PartiallyRefunded, self::Refunded], true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
