<?php

namespace App\Domains\Customers\Models;

use App\Domains\Catalog\Models\Product;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product the signed-in customer looked at (QC-043, recently viewed
 * synced across devices). One row per customer and product.
 */
#[Fillable(['customer_user_id', 'product_id', 'viewed_at'])]
class RecentlyViewedProduct extends Model
{
    use UsesCountryDatabase;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['viewed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
