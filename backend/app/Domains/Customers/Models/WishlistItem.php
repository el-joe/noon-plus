<?php

namespace App\Domains\Customers\Models;

use App\Domains\Catalog\Models\Product;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product saved to the customer's wishlist (G-W6), per country.
 */
#[Fillable(['customer_user_id', 'product_id'])]
class WishlistItem extends Model
{
    use UsesCountryDatabase;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
