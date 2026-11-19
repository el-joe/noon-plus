<?php

namespace App\Domains\Cart\Support;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\Catalog\Support\Measurements;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Domains\Warranty\Support\WarrantyPlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Cart operations. Every quantity change refreshes a 15-minute stock hold so
 * two shoppers can't take the last unit at the same time (FR-CHK-1) —
 * capped per guest line and in total lifetime (QA-010) — and is
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

    /**
     * The guest cart for this token, without creating one.
     */
    public function findGuest(?string $token): ?Cart
    {
        return $token === null || $token === '' ? null : Cart::query()->where('guest_token', $token)->first();
    }

    /**
     * The guest cart for this token, or a new one. A request without a known
     * token opens a new cart, so new guest carts are limited per IP
     * (`checkout.guest_carts_per_hour`, RT-002).
     */
    public function forGuest(?string $token, ?string $ip = null): Cart
    {
        if ($token !== null && ($cart = Cart::query()->where('guest_token', $token)->first())) {
            return $cart;
        }

        $ipHash = $ip === null ? null : self::ipHash($ip);

        if ($ipHash !== null) {
            $key = 'guest-carts:'.$ipHash;

            if (RateLimiter::tooManyAttempts($key, (int) config('nawy.checkout.guest_carts_per_hour', 10))) {
                throw new CartRejected('too_many_carts', __('Too many new carts from this connection. Try again later.'), 429);
            }

            RateLimiter::hit($key, 3600);
        }

        return Cart::query()->create(['guest_token' => Str::random(48), 'guest_ip_hash' => $ipHash]);
    }

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
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
            $this->hold($cart, $listingId, $quantity);
        });
    }

    /**
     * The stock hold of a cart line (QA-010): a guest cart holds at most
     * `checkout.guest_hold_max_quantity` units of a line, and a hold is
     * refreshed for `cart_lock_minutes` but never past
     * `cart_lock_max_minutes` after it was first taken. All guest carts
     * opened from one IP hold at most `checkout.guest_hold_max_per_ip`
     * units of a listing together (RT-002); past that the line stays in the
     * cart without holding stock (availability is checked again at checkout).
     */
    private function hold(Cart $cart, int $listingId, int $quantity): void
    {
        $held = $cart->customer_user_id === null ? min($quantity, (int) config('nawy.checkout.guest_hold_max_quantity', 3)) : $quantity;

        if ($cart->customer_user_id === null && $cart->guest_ip_hash !== null) {
            $heldBySameIp = (int) CartInventoryLock::query()
                ->where('listing_id', $listingId)
                ->where('cart_id', '!=', $cart->id)
                ->where('expires_at', '>', now())
                ->whereIn('cart_id', Cart::query()->select('id')->where('guest_ip_hash', $cart->guest_ip_hash))
                ->sum('quantity');
            $held = max(0, min($held, (int) config('nawy.checkout.guest_hold_max_per_ip', 6) - $heldBySameIp));
        }

        $lock = $cart->locks()->where('listing_id', $listingId)->first();

        if ($held === 0) {
            $lock?->delete();

            return;
        }

        $startedAt = $lock?->created_at ?? now();
        $expiresAt = min(now()->addMinutes((int) config('nawy.checkout.cart_lock_minutes')), $startedAt->copy()->addMinutes((int) config('nawy.checkout.cart_lock_max_minutes', 60)));

        if ($lock === null) {
            $cart->locks()->create(['listing_id' => $listingId, 'quantity' => $held, 'expires_at' => $expiresAt]);

            return;
        }

        $lock->forceFill(['quantity' => $held, 'expires_at' => $expiresAt])->save();
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
     * Measurements for a made-to-order line (MP v2 §14b), validated against
     * the category's measurement fields, plus a note to the designer.
     *
     * @param  array<string, mixed>  $measurements
     */
    public function setMeasurements(Cart $cart, int $listingId, array $measurements, ?string $note): void
    {
        $item = $cart->items()->where('listing_id', $listingId)->with('listing.variant.product.category')->first()
            ?? throw new CartRejected('item_not_in_cart', __('This item is not in your cart.'), 404);
        $product = $item->listing->variant->product;

        if (! app(Measurements::class)->requiredFor($product)) {
            throw new CartRejected('measurements_not_needed', __('This item is not made to order.'));
        }

        $item->forceFill([
            'measurements' => app(Measurements::class)->validate($product, $measurements),
            'measurement_note' => $note === null ? null : mb_substr($note, 0, 500),
        ])->save();
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
     * Move a guest cart into a customer's cart after sign-in (QA-008).
     * Quantities are summed and clipped to the stock still available and to
     * a running flash deal's per-customer allowance; a line that cannot be
     * merged at all is skipped. Every guest hold is released and the guest
     * cart is removed whatever happens to single lines.
     *
     * @return array<int, array{listing_id: int, code: string, message: string, requested: int, merged: int}> lines clipped or skipped
     */
    public function merge(Cart $guestCart, Cart $customerCart): array
    {
        $warnings = [];

        foreach ($guestCart->items()->get() as $item) {
            $guestCart->locks()->where('listing_id', $item->listing_id)->delete();
            $existing = (int) $customerCart->items()->where('listing_id', $item->listing_id)->value('quantity');
            $listing = Listing::query()->find($item->listing_id);
            $wanted = $existing + $item->quantity;

            if ($listing === null || $listing->status !== ListingStatus::Active) {
                $warnings[] = $this->mergeWarning($item->listing_id, 'listing_unavailable', __('This item is not available right now.'), $wanted, $existing);

                continue;
            }

            $quantity = min($wanted, $this->stockAvailability->availableFor($listing, $customerCart->id));
            $code = $quantity < $wanted ? 'insufficient_stock' : null;

            if ($deal = $this->flashPricing->activeDeals([$item->listing_id])->get($item->listing_id)) {
                $allowance = $this->flashPricing->allowance($deal, $customerCart->customer_user_id);

                if ($quantity > $allowance) {
                    $quantity = $allowance;
                    $code = 'flash_sale_customer_limit';
                }
            }

            if ($quantity <= $existing) {
                $warnings[] = $this->mergeWarning($item->listing_id, $code ?? 'insufficient_stock', __('This item could not be added from your guest cart.'), $wanted, $existing);

                continue;
            }

            try {
                $this->setQuantity($customerCart, $item->listing_id, $quantity);
                $customerCart->items()->where('listing_id', $item->listing_id)->whereNull('warranty_plan_id')->update(['warranty_plan_id' => $item->warranty_plan_id]);
            } catch (CartRejected $rejected) {
                $warnings[] = $this->mergeWarning($item->listing_id, $rejected->errorCode, $rejected->getMessage(), $wanted, $existing);

                continue;
            }

            if ($code !== null) {
                $warnings[] = $this->mergeWarning($item->listing_id, $code, __('Only :count of this item could be kept in your cart.', ['count' => $quantity]), $wanted, $quantity);
            }
        }

        $guestCart->locks()->delete();
        $guestCart->delete();

        return $warnings;
    }

    /**
     * @return array{listing_id: int, code: string, message: string, requested: int, merged: int}
     */
    private function mergeWarning(int $listingId, string $code, string $message, int $requested, int $merged): array
    {
        return ['listing_id' => $listingId, 'code' => $code, 'message' => $message, 'requested' => $requested, 'merged' => $merged];
    }
}
