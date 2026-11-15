<?php

namespace App\Domains\Coupons\Enums;

/**
 * What a coupon applies to (SRS §10 scopes, QC-014): the whole order, or
 * only items of some categories (sub-categories included), products,
 * brands or sellers (`coupons.target_ids`).
 */
enum CouponTarget: string
{
    case All = 'all';
    case Category = 'category';
    case Product = 'product';
    case Brand = 'brand';
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Whole order'),
            self::Category => __('Categories'),
            self::Product => __('Products'),
            self::Brand => __('Brands'),
            self::Vendor => __('Sellers'),
        };
    }
}
