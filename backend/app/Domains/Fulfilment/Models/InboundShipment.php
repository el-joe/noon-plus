<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's FBN inbound plan / advance shipping notice. Status moves only
 * through {@see InboundShipments}.
 */
#[Fillable(['vendor_id', 'warehouse_id', 'cartons', 'expected_at', 'carrier_name', 'tracking_number', 'notes'])]
class InboundShipment extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'fbn_inbound_shipments';

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InboundStatus::class,
            'cartons' => 'integer',
            'expected_at' => 'date',
            'submitted_at' => 'datetime', 'shipped_at' => 'datetime', 'arrived_at' => 'datetime',
            'received_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime',
        ];
    }

    public function transitionSubject(): string
    {
        return 'fbn_inbound_shipment';
    }

    /**
     * Reference shown before the plan is submitted.
     */
    public function reference(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }

    /**
     * @return HasMany<InboundLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InboundLine::class, 'fbn_inbound_shipment_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
