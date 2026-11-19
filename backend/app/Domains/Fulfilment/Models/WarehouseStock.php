<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one listing in one warehouse. Counters change only through
 * {@see WarehouseStockService}, which writes a movement per change and
 * never lets a counter (or available = on hand − reserved) go negative.
 */
#[Fillable(['warehouse_id', 'listing_id'])]
class WarehouseStock extends Model
{
    use UsesCountryDatabase;

    protected $table = 'warehouse_stock';

    /**
     * @var array<string, int>
     */
    protected $attributes = ['on_hand' => 0, 'reserved' => 0, 'damaged' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer', 'reserved' => 'integer', 'damaged' => 'integer',
            'first_received_at' => 'datetime', 'damaged_since' => 'datetime',
            'long_term_flagged_at' => 'datetime', 'unsellable_flagged_at' => 'datetime',
        ];
    }

    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
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
