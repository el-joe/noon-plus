<?php

namespace App\Domains\Customers\Models;

use App\Domains\Shipping\Models\ShippingZone;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved delivery address in the customer's address book (G-W5), one
 * book per country. The phone is stored in E.164; the shipping zone is
 * resolved from city + area when saved.
 */
#[Fillable([
    'customer_user_id', 'label', 'name', 'phone', 'city', 'area', 'street', 'building', 'apartment', 'landmark',
    'shipping_zone_id', 'latitude', 'longitude', 'is_default',
])]
class CustomerAddress extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * The address as an order's `shipping_address` snapshot.
     *
     * @return array<string, mixed>
     */
    public function toOrderAddress(): array
    {
        return array_filter([
            'name' => $this->name,
            'phone' => $this->phone,
            'city' => $this->city,
            'area' => $this->area,
            'street' => $this->street,
            'building' => $this->building,
            'apartment' => $this->apartment,
            'landmark' => $this->landmark,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'address_id' => $this->id,
        ], fn (mixed $value): bool => $value !== null);
    }
}
