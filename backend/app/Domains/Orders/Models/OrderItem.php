<?php

namespace App\Domains\Orders\Models;

use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sub-order with its price, flash deal, warranty plan and the
 * return window (days) promised at purchase.
 */
#[Fillable([
    'sub_order_id', 'listing_id', 'sku', 'title', 'quantity', 'unit_price', 'regular_unit_price', 'line_total',
    'flash_sale_submission_id', 'warranty_plan_id', 'warranty_unit_price', 'warranty_total', 'return_window_days', 'commission_rate', 'fulfillment_status', 'measurements', 'measurement_note',
])]
class OrderItem extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['measurements' => 'array'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }
}
