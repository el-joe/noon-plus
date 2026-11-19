<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\ReservationStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Warehouse units held for one order item (one row per warehouse used).
 */
#[Fillable(['order_item_id', 'sub_order_id', 'listing_id', 'warehouse_id', 'quantity', 'status'])]
class FbnReservation extends Model
{
    use UsesCountryDatabase;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ReservationStatus::class, 'quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
