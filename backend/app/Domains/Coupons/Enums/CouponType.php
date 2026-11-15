<?php

namespace App\Domains\Coupons\Enums;

/**
 * Coupon types (SRS §10, QC-014): percent off, a fixed amount off, free
 * shipping, or buy X get Y (BOGO: for every `buy_quantity` eligible units
 * the cheapest `get_quantity` units get `value` % off — 100 = free).
 */
enum CouponType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FreeShipping = 'free_shipping';
    case Bogo = 'bogo';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => __('Percent off'),
            self::FixedAmount => __('Fixed amount off'),
            self::FreeShipping => __('Free shipping'),
            self::Bogo => __('Buy X get Y (BOGO)'),
        };
    }
}
