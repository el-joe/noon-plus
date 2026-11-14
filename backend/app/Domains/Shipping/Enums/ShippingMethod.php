<?php

namespace App\Domains\Shipping\Enums;

/**
 * Delivery methods a zone can offer (SRS §8).
 */
enum ShippingMethod: string
{
    case Standard = 'standard';
    case Express = 'express';
    case SameDay = 'same_day';

    /**
     * Same-day delivery only works when every item can leave today, before
     * the cut-off on a business day.
     */
    public function needsZeroProcessingDays(): bool
    {
        return $this === self::SameDay;
    }
}
