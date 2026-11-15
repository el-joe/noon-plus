<?php

namespace App\Domains\Finance\Enums;

use Carbon\CarbonInterface;

/**
 * Vendor payout schedule (settings catalogue `payout.schedule`, SRS §9):
 * weekly on Monday, biweekly on the 1st and 15th, monthly on the 1st —
 * in the country's local date.
 */
enum PayoutSchedule: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function isDue(CarbonInterface $localDate): bool
    {
        return match ($this) {
            self::Weekly => $localDate->isMonday(),
            self::Biweekly => in_array($localDate->day, [1, 15], true),
            self::Monthly => $localDate->day === 1,
        };
    }
}
