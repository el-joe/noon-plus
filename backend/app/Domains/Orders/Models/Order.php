<?php

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Support\OrderStatusRollup;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master order of one checkout (FR-CHK-5). Its status is derived from the
 * sub-orders by {@see OrderStatusRollup}.
 */
#[Fillable([
    'number', 'customer_user_id', 'status', 'payment_method', 'payment_status', 'currency', 'subtotal', 'discount_total',
    'shipping_total', 'shipping_subsidy_total', 'cod_fee', 'warranty_total', 'grand_total', 'tax_included', 'tax_rate', 'shipping_address', 'placed_at',
])]
class Order extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipping_address' => 'array',
            'placed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<SubOrder, $this>
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class)->orderBy('id');
    }
}
