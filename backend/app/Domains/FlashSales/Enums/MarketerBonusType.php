<?php

namespace App\Domains\FlashSales\Enums;

/**
 * Flash-sale bonus for marketers on conversions during the live event
 * (SRS §10, setting `flash_sale.marketer_bonus`).
 */
enum MarketerBonusType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
