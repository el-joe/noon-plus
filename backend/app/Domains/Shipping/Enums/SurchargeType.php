<?php

namespace App\Domains\Shipping\Enums;

enum SurchargeType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
}
