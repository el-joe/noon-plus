<?php

namespace App\Domains\Cart\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Soft hold on stock while an item sits in a cart (15 minutes, FR-CHK-1).
 */
#[Fillable(['cart_id', 'listing_id', 'quantity', 'expires_at'])]
class CartInventoryLock extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
