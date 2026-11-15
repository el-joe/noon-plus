<?php

namespace App\Domains\Membership\Enums;

use Carbon\CarbonInterface;

enum MembershipInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function after(CarbonInterface $start): CarbonInterface
    {
        return $this === self::Monthly ? $start->addMonthNoOverflow() : $start->addYearNoOverflow();
    }
}
