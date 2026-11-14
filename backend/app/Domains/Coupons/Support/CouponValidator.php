<?php

namespace App\Domains\Coupons\Support;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Models\Coupon;

/**
 * Checks whether a coupon can be used on a cart (SRS §10): active, inside its
 * dates, under its usage limits, seller in the cart for seller coupons, and
 * no other coupon of the same scope already applied.
 */
class CouponValidator
{
    public function assertApplicable(Coupon $coupon, Cart $cart, ?int $customerUserId): void
    {
        if (! $coupon->is_active || $coupon->starts_at?->isFuture() || $coupon->ends_at?->isPast()) {
            throw new CartRejected('coupon_inactive', __('This coupon is not active.'));
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            throw new CartRejected('coupon_used_up', __('This coupon has been fully used.'));
        }

        if ($coupon->per_customer_limit !== null && $customerUserId !== null
            && $coupon->redemptions()->where('customer_user_id', $customerUserId)->count() >= $coupon->per_customer_limit) {
            throw new CartRejected('coupon_already_used', __('You have already used this coupon.'));
        }

        if ($coupon->scope === CouponScope::Vendor
            && ! $cart->items()->whereHas('listing', fn ($listing) => $listing->where('vendor_id', $coupon->vendor_id))->exists()) {
            throw new CartRejected('coupon_seller_not_in_cart', __('This coupon is for a seller who is not in your cart.'));
        }

        $sameScopeApplied = $cart->coupons()
            ->where('coupons.scope', $coupon->scope)
            ->where('coupons.id', '!=', $coupon->id)
            ->exists();

        if ($sameScopeApplied) {
            throw new CartRejected('coupon_limit', __('You can use one Nawy coupon and one seller coupon per order.'));
        }
    }
}
