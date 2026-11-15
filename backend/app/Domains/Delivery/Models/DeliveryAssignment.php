<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt of a shipment by one agent (SRS §4.6, ADR 15). The
 * 4-digit delivery code is generated per assignment: shown to the agent and
 * to the customer; the customer reads it aloud and the agent taps
 * "Codes match". Proof photo and signature are file references on the
 * private disk.
 */
#[Fillable([
    'number', 'shipment_id', 'sub_order_id', 'delivery_agent_id', 'carrier_id', 'attempt_no', 'status', 'delivery_code',
    'assigned_via', 'assigned_by_user_id', 'currency', 'cod_amount', 'sla_due_at', 'sla_breached_at', 'accepted_at',
    'picked_up_at', 'arrived_at', 'delivered_at', 'failed_at', 'failure_reason', 'failure_note', 'decline_reason',
    'proof_photo_path', 'signature_path',
])]
#[Hidden(['delivery_code'])]
class DeliveryAssignment extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'assigned', 'assigned_via' => 'manual', 'attempt_no' => 1];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'sla_due_at' => 'immutable_datetime',
            'sla_breached_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'picked_up_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<DeliveryAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(DeliveryAgent::class, 'delivery_agent_id');
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function hasProof(): bool
    {
        return $this->proof_photo_path !== null || $this->signature_path !== null;
    }
}
