<?php

namespace App\Domains\Cart\Models;

use App\Domains\Listings\Models\Listing;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_id', 'listing_id', 'quantity', 'warranty_plan_id', 'measurements', 'measurement_note'])]
class CartItem extends Model
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
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Extended warranty chosen for this line (FR-CHK-2).
     *
     * @return BelongsTo<WarrantyPlan, $this>
     */
    public function warrantyPlan(): BelongsTo
    {
        return $this->belongsTo(WarrantyPlan::class);
    }
}
