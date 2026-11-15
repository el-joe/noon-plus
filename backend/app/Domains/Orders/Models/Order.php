<?php

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Support\OrderStatusRollup;
use App\Domains\Payments\Models\Payment;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master order of one checkout (FR-CHK-5). Its status is derived from the
 * sub-orders by {@see OrderStatusRollup}; `payment_status` is derived from
 * the payment attempts and `payment_due_at` ends the hold window of an
 * unpaid card/BNPL order.
 */
#[Fillable([
    'number', 'customer_user_id', 'status', 'payment_method', 'payment_status', 'payment_due_at', 'currency', 'subtotal', 'discount_total',
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
            'payment_due_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<SubOrder, $this>
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class)->orderBy('id');
    }

    /**
     * Payment attempts, oldest first (FR-CHK-3).
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * The newest payment attempt.
     */
    public function latestPayment(): ?Payment
    {
        return $this->payments->last();
    }
}
