<?php

namespace App\Domains\Coupons\Support;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Membership\Support\Memberships;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;

/**
 * Checks whether a coupon can be used on a cart (SRS §10, QC-014): active,
 * inside its dates, under its usage limits, the customer eligible (first
 * order, segment), at least one eligible item in the cart (seller,
 * category, product or brand targets), and no other coupon of the same
 * scope already applied.
 */
class CouponValidator
{
    public function __construct(private CouponTargets $targets) {}

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

        $this->assertCustomerEligible($coupon, $customerUserId);

        if ($coupon->scope === CouponScope::Vendor
            && ! $cart->items()->whereHas('listing', fn ($listing) => $listing->where('vendor_id', $coupon->vendor_id))->exists()) {
            throw new CartRejected('coupon_seller_not_in_cart', __('This coupon is for a seller who is not in your cart.'));
        }

        if ($coupon->target_type !== CouponTarget::All) {
            $items = $cart->items()->with('listing.variant.product')->get();

            // A seller coupon only discounts that seller's items, so only those can make it eligible.
            $eligible = $items->first(fn ($item): bool => ($coupon->scope !== CouponScope::Vendor || (int) $item->listing->vendor_id === (int) $coupon->vendor_id)
                && $this->targets->covers($coupon, $item->listing));

            if ($eligible === null) {
                throw new CartRejected('coupon_no_eligible_items', __('None of the items in your cart are eligible for this coupon.'));
            }
        }

        $sameScopeApplied = $cart->coupons()
            ->where('coupons.scope', $coupon->scope)
            ->where('coupons.id', '!=', $coupon->id)
            ->exists();

        if ($sameScopeApplied) {
            throw new CartRejected('coupon_limit', __('You can use one Nawy coupon and one seller coupon per order.'));
        }
    }

    /**
     * First-order coupons and customer segments (QC-014).
     */
    public function assertCustomerEligible(Coupon $coupon, ?int $customerUserId): void
    {
        $reason = $this->customerIneligibility($coupon, $customerUserId);

        if ($reason !== null) {
            throw new CartRejected($reason[0], $reason[1]);
        }
    }

    /**
     * @return array{0: string, 1: string}|null [code, message] when the customer may not use it
     */
    public function customerIneligibility(Coupon $coupon, ?int $customerUserId): ?array
    {
        if (! $coupon->needsCustomer()) {
            return null;
        }

        if ($customerUserId === null) {
            return ['coupon_sign_in_required', __('Sign in to use this coupon.')];
        }

        $hasOrdered = fn (): bool => Order::query()->where('customer_user_id', $customerUserId)->where('status', '!=', OrderStatus::Cancelled)->exists();

        if ($coupon->first_order_only && $hasOrdered()) {
            return ['coupon_first_order_only', __('This coupon is for your first order only.')];
        }

        $eligible = match ($coupon->customer_segment ?? CustomerSegment::Everyone) {
            CustomerSegment::Everyone => true,
            CustomerSegment::NewCustomers => ! $hasOrdered(),
            CustomerSegment::ReturningCustomers => $hasOrdered(),
            CustomerSegment::NawyOneMembers => app(Memberships::class)->current($customerUserId)?->givesBenefits() ?? false,
        };

        return $eligible ? null : ['coupon_segment', __('This coupon is not available for your account.')];
    }
}
