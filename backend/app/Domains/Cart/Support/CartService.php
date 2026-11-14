<?php

namespace App\Domains\Cart\Support;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Domains\Warranty\Support\WarrantyPlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cart operations. Every quantity change refreshes a 15-minute stock hold so
 * two shoppers can't take the last unit at the same time (FR-CHK-1), and is
 * checked against running flash deals (per-customer maximum, units left).
 */
class CartService
{
    public function __construct(
        private StockAvailability $stockAvailability,
        private FlashSalePricing $flashPricing,
        private WarrantyPlanCatalog $warrantyPlans,
    ) {}

    public function forCustomer(int $userId): Cart
    {
        return Cart::query()->firstOrCreate(['customer_user_id' => $userId]);
    }

    public function forGuest(?string $token): Cart
    {
        if ($token !== null && ($cart = Cart::query()->where('guest_token', $token)->first())) {
            return $cart;
        }

        return Cart::query()->create(['guest_token' => Str::random(48)]);
    }

    public function setQuantity(Cart $cart, int $listingId, int $quantity): void
    {
        $connection = $cart->getConnectionName();

        DB::connection($connection)->transaction(function () use ($cart, $listingId, $quantity): void {
            $listing = Listing::query()->whereKey($listingId)->lockForUpdate()->first();

            if ($listing === null || $listing->status !== ListingStatus::Active) {
                throw new CartRejected('listing_unavailable', __('This item is not available right now.'));
            }

            if ($quantity <= 0) {
                $this->remove($cart, $listingId);

                return;
            }

            $available = $this->stockAvailability->availableFor($listing, $cart->id);

            if ($quantity > $available) {
                throw new CartRejected('insufficient_stock', trans_choice('Only :count left in stock.|Only :count left in stock.', $available, ['count' => $available]));
            }

            if ($deal = $this->flashPricing->activeDeals([$listingId])->get($listingId)) {
                $this->flashPricing->assertWithinLimits($deal, $quantity, $cart->customer_user_id);
            }

            $cart->items()->updateOrCreate(['listing_id' => $listingId], ['quantity' => $quantity]);
            $cart->locks()->updateOrCreate(
                ['listing_id' => $listingId],
                ['quantity' => $quantity, 'expires_at' => now()->addMinutes((int) config('nawy.checkout.cart_lock_minutes'))],
            );
        });
    }

    /**
     * Choose (or clear with null) the extended warranty for a cart line. The
     * plan must belong to the product's category or its parent.
     */
    public function setWarranty(Cart $cart, int $listingId, ?int $warrantyPlanId): void
    {
        $item = $cart->items()->where('listing_id', $listingId)->with('listing.variant.product')->first()
            ?? throw new CartRejected('item_not_in_cart', __('This item is not in your cart.'), 404);

        if ($warrantyPlanId !== null) {
            $plan = WarrantyPlan::query()->where('is_active', true)->find($warrantyPlanId);

            if ($plan === null || ! $this->warrantyPlans->isAvailableFor($plan, (int) $item->listing->variant->product->category_id)) {
                throw new CartRejected('warranty_not_available', __('This warranty plan is not available for this item.'));
            }
        }

        $item->forceFill(['warranty_plan_id' => $warrantyPlanId])->save();
    }

    /**
     * Remember where the cart ships to and the preferred delivery method.
     */
    public function setShipping(Cart $cart, ?string $city, ?string $area, ShippingMethod $method): void
    {
        $cart->forceFill([
            'destination_city' => $city,
            'destination_area' => $area,
            'shipping_method' => $method,
        ])->save();
    }

    public function remove(Cart $cart, int $listingId): void
    {
        $cart->items()->where('listing_id', $listingId)->delete();
        $cart->locks()->where('listing_id', $listingId)->delete();
    }

    /**
     * Move a guest cart into a customer's cart after sign-in. Quantities are
     * summed and capped at what is still available.
     */
    public function merge(Cart $guestCart, Cart $customerCart): void
    {
        foreach ($guestCart->items()->get() as $item) {
            $existing = (int) $customerCart->items()->where('listing_id', $item->listing_id)->value('quantity');
            $listing = Listing::query()->find($item->listing_id);

            if ($listing === null || $listing->status !== ListingStatus::Active) {
                continue;
            }

            $wanted = $existing + $item->quantity;
            $guestCart->locks()->where('listing_id', $item->listing_id)->delete();
            $quantity = min($wanted, $this->stockAvailability->availableFor($listing, $customerCart->id));

            if ($quantity > 0) {
                $this->setQuantity($customerCart, $item->listing_id, $quantity);
                $customerCart->items()->where('listing_id', $item->listing_id)->whereNull('warranty_plan_id')->update(['warranty_plan_id' => $item->warranty_plan_id]);
            }
        }

        $guestCart->delete();
    }
}
