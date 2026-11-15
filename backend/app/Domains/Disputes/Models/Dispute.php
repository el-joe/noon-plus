<?php

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeResolution;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer ↔ seller dispute about one shipment, decided by an admin (SRS §7.6).
 */
#[Fillable(['number', 'order_id', 'sub_order_id', 'vendor_id', 'customer_user_id', 'status', 'reason', 'resolution', 'refund_amount', 'refund_id', 'resolved_at'])]
class Dispute extends Model
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
            'status' => DisputeStatus::class,
            'reason' => DisputeReason::class,
            'resolution' => DisputeResolution::class,
            'resolved_at' => 'immutable_datetime',
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
     * @return HasMany<DisputeMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('id');
    }
}
