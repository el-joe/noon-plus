<?php

namespace App\Domains\Shipping\Models;

use App\Domains\Shipping\Enums\ShippingMethod;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery method in a zone: base fee covering `base_weight_kg`, a fee
 * per started kg above it (or weight slabs when defined), transit days and an
 * optional order cut-off time (country local).
 */
#[UseFactory(ShippingRateFactory::class)]
#[Fillable([
    'shipping_zone_id', 'method', 'base_fee', 'base_weight_kg', 'per_kg_fee',
    'transit_min_days', 'transit_max_days', 'cutoff_time', 'is_active',
])]
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => ShippingMethod::class,
            'base_fee' => 'decimal:4',
            'base_weight_kg' => 'decimal:3',
            'per_kg_fee' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * @return HasMany<ShippingWeightSlab, $this>
     */
    public function slabs(): HasMany
    {
        return $this->hasMany(ShippingWeightSlab::class)->orderBy('max_kg');
    }
}
