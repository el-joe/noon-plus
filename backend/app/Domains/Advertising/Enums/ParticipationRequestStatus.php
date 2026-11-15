<?php

namespace App\Domains\Advertising\Enums;

/**
 * Coupon participation request (SRS §10, lifecycle catalogue §4.6).
 */
enum ParticipationRequestStatus: string
{
    case Requested = 'requested';
    case ApprovedPendingPayment = 'approved_pending_payment';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Paid, self::ApprovedPendingPayment, self::Rejected],
            self::ApprovedPendingPayment => [self::Paid, self::Rejected],
            self::Paid => [self::Refunded],
            default => [],
        };
    }
}
