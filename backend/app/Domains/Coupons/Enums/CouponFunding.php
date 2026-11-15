<?php

namespace App\Domains\Coupons\Enums;

/**
 * Who pays for the discount (QC-014). A platform-funded discount is Nawy's
 * promo expense; a vendor-funded one reduces each seller's settlement by
 * their share. Seller coupons are always vendor-funded.
 */
enum CouponFunding: string
{
    case Platform = 'platform';
    case Vendor = 'vendor';
}
