<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SKU on an inbound plan: expected units and cartons, then what the
 * warehouse counted (good, damaged) and the bin it was put away in.
 */
#[Fillable(['fbn_inbound_shipment_id', 'listing_id', 'quantity_expected', 'cartons'])]
class InboundLine extends Model
{
    use UsesCountryDatabase;

    protected $table = 'fbn_inbound_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity_expected' => 'integer', 'cartons' => 'integer', 'quantity_received' => 'integer', 'quantity_damaged' => 'integer'];
    }

    /**
     * Units expected but not counted (short), never negative.
     */
    public function shortQuantity(): int
    {
        return max(0, $this->quantity_expected - $this->quantity_received - $this->quantity_damaged);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<InboundShipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(InboundShipment::class, 'fbn_inbound_shipment_id');
    }
}
