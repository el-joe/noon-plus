<?php

namespace App\Domains\Warranty\Enums;

enum WarrantyOutcome: string
{
    case Repair = 'repair';
    case Replace = 'replace';
    case Refund = 'refund';

    public function status(): WarrantyClaimStatus
    {
        return match ($this) {
            self::Repair => WarrantyClaimStatus::InRepair,
            self::Replace => WarrantyClaimStatus::ReplacementSent,
            self::Refund => WarrantyClaimStatus::RefundIssued,
        };
    }
}
