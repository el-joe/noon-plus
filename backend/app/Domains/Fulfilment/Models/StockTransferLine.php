<?php

namespace App\Domains\Fulfilment\Models;

use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fbn_transfer_id', 'listing_id', 'quantity'])]
class StockTransferLine extends Model
{
    use UsesCountryDatabase;

    protected $table = 'fbn_transfer_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quantity_received' => 'integer'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
