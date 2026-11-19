<?php

namespace App\Domains\Cart\Models;

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Support\ShippingDestination;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shopping cart: either a signed-in customer's (one per country) or a guest
 * cart identified by the `X-Cart-Token` header (FR-CHK-1). It remembers the
 * delivery city/area and the chosen shipping method for the live quote.
 */
#[Fillable(['customer_user_id', 'guest_token', 'guest_ip_hash', 'destination_city', 'destination_area', 'shipping_method'])]
class Cart extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shipping_method' => ShippingMethod::class];
    }

    public function destination(): ShippingDestination
    {
        return new ShippingDestination($this->destination_city, $this->destination_area);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<CartInventoryLock, $this>
     */
    public function locks(): HasMany
    {
        return $this->hasMany(CartInventoryLock::class);
    }

    /**
     * @return BelongsToMany<Coupon, $this>
     */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'cart_coupons')->withTimestamps();
    }
}
