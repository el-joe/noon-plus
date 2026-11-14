<?php

namespace App\Domains\Shipping\Models;

use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Enums\SurchargeType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Extra shipping charge (e.g. FBN outbound, remote area, seller city
 * surcharge). Each filter left empty matches everything.
 */
#[Fillable(['name', 'shipping_zone_id', 'vendor_id', 'method', 'fulfilment', 'type', 'value', 'is_active'])]
class ShippingSurcharge extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => ShippingMethod::class,
            'type' => SurchargeType::class,
            'value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
