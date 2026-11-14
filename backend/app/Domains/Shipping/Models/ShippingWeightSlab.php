<?php

namespace App\Domains\Shipping\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Flat fee for parcels up to `max_kg` (billable weight).
 */
#[Fillable(['shipping_rate_id', 'max_kg', 'fee'])]
class ShippingWeightSlab extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['max_kg' => 'decimal:3', 'fee' => 'decimal:4'];
    }
}
