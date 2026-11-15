<?php

namespace App\Domains\Payments\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\RefundStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A full or partial refund of a payment, unique per idempotency key so a
 * retried request never refunds twice.
 */
#[Fillable(['payment_id', 'idempotency_key', 'amount', 'status', 'gateway_reference', 'reason', 'failure_message', 'actor_type', 'actor_user_id', 'completed_at'])]
class PaymentRefund extends Model
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
            'status' => RefundStatus::class,
            'actor_type' => ActorType::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
