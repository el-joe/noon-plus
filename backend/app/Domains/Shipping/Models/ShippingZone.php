<?php

namespace App\Domains\Shipping\Models;

use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of cities/areas with its own delivery methods and rates (SRS §8).
 * An exceptional zone lets opted-in sellers offer free delivery: the
 * seller pays `vendor_subsidy_share_percent` of the cost, the platform the rest.
 */
#[UseFactory(ShippingZoneFactory::class)]
#[Fillable(['name', 'is_default', 'is_exceptional', 'vendor_subsidy_share_percent', 'is_active'])]
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_exceptional' => 'boolean',
            'is_active' => 'boolean',
            'vendor_subsidy_share_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ShippingZoneArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(ShippingZoneArea::class);
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /**
     * Sellers who opted in to subsidised free delivery in this zone.
     *
     * @return BelongsToMany<Vendor, $this>
     */
    public function optedInVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'shipping_zone_vendor_opt_ins')->withTimestamps();
    }
}
