<?php

namespace App\Domains\Returns\Models;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Support\ReturnRequests;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer return of items of one delivered sub-order (SRS §7.4). Moves
 * only through {@see ReturnRequests}.
 */
#[Fillable([
    'number', 'order_id', 'sub_order_id', 'vendor_id', 'customer_user_id', 'status', 'reason', 'comment', 'method', 'refund_destination',
    'photos', 'rejection_reason', 'carrier_investigation', 'refund_total', 'refund_id', 'completed_at',
])]
class ReturnRequest extends Model
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
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'method' => ReturnMethod::class,
            'refund_destination' => RefundDestination::class,
            'photos' => 'array',
            'carrier_investigation' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function transitionSubject(): string
    {
        return 'return';
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
