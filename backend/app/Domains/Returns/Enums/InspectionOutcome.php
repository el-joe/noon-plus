<?php

namespace App\Domains\Returns\Enums;

/**
 * Inspection result per returned item (SRS §7.4): good condition → full
 * refund; damaged by the customer → partial or no refund; damaged in
 * transit → full refund and the carrier is investigated.
 */
enum InspectionOutcome: string
{
    case Good = 'good';
    case CustomerDamaged = 'customer_damaged';
    case TransitDamaged = 'transit_damaged';

    public function refundsInFull(): bool
    {
        return $this !== self::CustomerDamaged;
    }
}
