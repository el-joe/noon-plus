<?php

namespace App\Domains\Orders\Models;

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
 */
#[Fillable([
    'order_id', 'vendor_id', 'number', 'subtotal', 'discount_total', 'shipping_total', 'shipping_method', 'shipping_zone_id',
    'shipping_cost', 'shipping_subsidy_vendor', 'shipping_subsidy_platform', 'shipping_absorbed', 'warranty_total', 'total',
    'estimated_delivery_from', 'estimated_delivery_by',
])]
class SubOrder extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'placed'];

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
}
