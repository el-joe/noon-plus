<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one listing on a removal order, from sellable or damaged stock.
 */
#[Fillable(['fbn_removal_order_id', 'listing_id', 'source', 'quantity'])]
class RemovalLine extends Model
{
    use UsesCountryDatabase;

    public const SOURCE_SELLABLE = 'sellable';

    public const SOURCE_DAMAGED = 'damaged';

    protected $table = 'fbn_removal_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<RemovalOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RemovalOrder::class, 'fbn_removal_order_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
