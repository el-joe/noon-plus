<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Shipping\Models\ShippingZone;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Fulfilled-by-Nawy warehouse in the country (admin → Inventory &
 * fulfilment → Warehouses). Capacity is in cubic feet, the unit FBN storage
 * is billed in.
 */
#[Fillable(['code', 'name_en', 'name_ar', 'address', 'city', 'shipping_zone_id', 'capacity_cubic_feet', 'is_active'])]
class Warehouse extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity_cubic_feet' => 'decimal:3', 'over_capacity_since' => 'datetime'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * @param  Builder<Warehouse>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * @return HasMany<WarehouseStock, $this>
     */
    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    protected function auditLogName(): string
    {
        return 'fbn';
    }
}
