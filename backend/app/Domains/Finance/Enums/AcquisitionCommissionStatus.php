<?php

namespace App\Domains\Finance\Enums;

/**
 * Acquisition commission (FR-FIN-6, SRS §4.1.5, §9): computed monthly as
 * pending → approved by a finance admin (not the acquirer) → paid by bank
 * transfer; pending or approved → rejected (the accrual is reversed).
 */
enum AcquisitionCommissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Paid, self::Rejected],
            self::Paid, self::Rejected => [],
        };
    }
}
