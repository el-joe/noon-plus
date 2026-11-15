<?php

namespace App\Domains\Payments\Models;

use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Support\PaymentStateMachine;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment attempt for an order (FR-CHK-3). A failed attempt keeps the
 * order on hold so the customer can retry with another method until
 * `expires_at`. Status changes only through {@see PaymentStateMachine}.
 * A payment without an order pays a `payable` (gift card purchase,
 * membership invoice — WP4).
 */
#[Fillable([
    'reference', 'order_id', 'payable_type', 'payable_id', 'customer_user_id', 'method', 'mode', 'currency', 'amount', 'idempotency_key',
    'gateway_reference', 'redirect_url', 'client_data', 'expires_at',
])]
#[Hidden(['client_data', 'idempotency_key'])]
class Payment extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'pending', 'captured_amount' => '0', 'refunded_amount' => '0'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'client_data' => 'array',
            'expires_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
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
     * @return HasMany<PaymentStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(PaymentStatusChange::class)->orderBy('id');
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('id');
    }
}
