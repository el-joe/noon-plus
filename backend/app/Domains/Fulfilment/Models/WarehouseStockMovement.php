<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\StockBucket;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only change of one warehouse stock counter, with the reason, the
 * document it belongs to and the actor (the audit trail of receiving,
 * reservations, dispatch, transfers, removals and adjustments).
 */
#[Fillable(['warehouse_id', 'listing_id', 'bucket', 'quantity', 'reason', 'reference_type', 'reference_id', 'actor_user_id', 'note'])]
class WarehouseStockMovement extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['bucket' => StockBucket::class, 'quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Warehouse stock movements are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Warehouse stock movements are append-only.'));
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
