<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Enums\ClaimType;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A claim against a carrier for a lost or damaged shipment (SRS §4.1.11).
 * Compensation is booked Dr carrier receivable / Cr the seller's payable or
 * the customer's Nawy Credits.
 */
#[Fillable([
    'number', 'carrier_id', 'shipment_id', 'sub_order_id', 'type', 'description', 'currency', 'claimed_amount',
    'approved_amount', 'compensate_to', 'status', 'raised_by_type', 'raised_by_user_id', 'decision_note', 'carrier_response', 'carrier_responded_at', 'compensated_at',
])]
class CarrierClaim extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const COMPENSATE_VENDOR = 'vendor';

    public const COMPENSATE_CUSTOMER = 'customer';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ClaimStatus::class, 'type' => ClaimType::class, 'compensated_at' => 'immutable_datetime', 'carrier_responded_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
