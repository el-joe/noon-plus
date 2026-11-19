<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The forward shipment of one sub-order (SRS §8): every delivery attempt is
 * an assignment; failed attempts are rescheduled or returned to origin.
 */
#[Fillable(['number', 'sub_order_id', 'carrier_id', 'status', 'attempts', 'rescheduled_for', 'last_failure_reason', 'delivered_at', 'returned_at'])]
class Shipment extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'label_created', 'attempts' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'rescheduled_for' => 'immutable_date',
            'delivered_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<DeliveryAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderBy('id');
    }

    public function openAssignment(): ?DeliveryAssignment
    {
        return $this->assignments()->whereIn('status', AssignmentStatus::open())->reorder('id', 'desc')->first();
    }
}
