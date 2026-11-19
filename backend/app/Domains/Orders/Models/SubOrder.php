<?php

namespace App\Domains\Orders\Models;

use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One seller's part of an order. All settlement happens at this level:
 * `total` is the seller's goods − discount + shipping charged; the shipping
 * cost, subsidy split (seller / platform) and the amount the platform
 * absorbed for free shipping are kept for payouts and P&L.
 *
 * `fulfilled_by` is `nawy` for the warehouse parcel split off a seller's
 * checkout group that mixed FBN and seller-fulfilled items (owner decision
 * 2026-11-19); `split_from_sub_order_id` then points at the seller's parcel.
 */
#[Fillable([
    'order_id', 'vendor_id', 'fulfilled_by', 'split_from_sub_order_id', 'number', 'subtotal', 'discount_total', 'shipping_total', 'shipping_method', 'shipping_zone_id',
    'shipping_cost', 'shipping_subsidy_vendor', 'shipping_subsidy_platform', 'shipping_absorbed', 'warranty_total', 'total',
    'estimated_delivery_from', 'estimated_delivery_by',
])]
class SubOrder extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'placed', 'fulfilled_by' => 'seller'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubOrderStatus::class,
            'delivered_at' => 'immutable_datetime',
            'cod_remittance_confirmed' => 'boolean',
            'estimated_delivery_from' => 'immutable_date',
            'estimated_delivery_by' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The seller's parcel this warehouse parcel was split from.
     *
     * @return BelongsTo<SubOrder, $this>
     */
    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class, 'split_from_sub_order_id');
    }

    public function isFbnSplit(): bool
    {
        return $this->split_from_sub_order_id !== null;
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /**
     * Delivery attempts (QC-050: eager-loaded by the customer orders list).
     *
     * @return HasMany<DeliveryAssignment, $this>
     */
    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderBy('id');
    }
}
