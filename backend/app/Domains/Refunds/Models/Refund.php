<?php

namespace App\Domains\Refunds\Models;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Models\PaymentRefund;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Support\Refunds;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer refund (SRS §7.5) for one sub-order, created by a return,
 * dispute, warranty claim, cancellation or admin. Moves only through
 * {@see Refunds}; the gateway part is a `payment_refunds` row.
 */
#[Fillable([
    'number', 'order_id', 'sub_order_id', 'customer_user_id', 'source_type', 'source_id', 'currency', 'amount', 'warranty_amount',
    'destination', 'status', 'idempotency_key', 'payment_refund_id', 'after_settlement', 'reason', 'failure_message', 'processed_at',
])]
class Refund extends Model
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
            'status' => RefundStatus::class,
            'source_type' => RefundSource::class,
            'destination' => RefundDestination::class,
            'after_settlement' => 'boolean',
            'processed_at' => 'immutable_datetime',
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
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<PaymentRefund, $this>
     */
    public function paymentRefund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class);
    }
}
