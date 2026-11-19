<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\PackagingOrderStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store's packaging order (lifecycle catalogue §6.3). Unit costs are
 * snapshotted on the items; the delivery fee is charged in full (never
 * subsidised).
 *
 * @property PackagingOrderStatus $status
 */
#[Fillable([
    'number', 'vendor_id', 'ordered_by_user_id', 'status', 'currency', 'items_total', 'delivery_fee', 'total', 'delivery_address', 'notes',
    'carrier_name', 'tracking_number', 'rejection_reason', 'approved_at', 'shipped_at', 'delivered_at',
])]
class PackagingOrder extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PackagingOrderStatus::class,
            'delivery_address' => 'array',
            'approved_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PackagingOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackagingOrderItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
