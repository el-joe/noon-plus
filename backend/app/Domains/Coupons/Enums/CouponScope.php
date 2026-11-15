<?php

namespace App\Domains\Coupons\Enums;

/**
 * Who issues the coupon — also its stacking slot: at most one coupon of each
 * scope per order (one Nawy + one seller coupon, SRS §10). What it applies
 * to is the {@see CouponTarget}; who pays for it is {@see CouponFunding}.
 */
enum CouponScope: string
{
    case Platform = 'platform';
    case Vendor = 'vendor';
}
