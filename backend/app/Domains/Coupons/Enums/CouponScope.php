<?php

namespace App\Domains\Coupons\Enums;

/**
 * At most one coupon of each scope per order (SRS §10).
 */
enum CouponScope: string
{
    case Platform = 'platform';
    case Vendor = 'vendor';
}
