<?php

namespace App\Domains\Shipping\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A city (area null = the whole city) or one area of a city in a zone.
 */
#[Fillable(['shipping_zone_id', 'city', 'area'])]
class ShippingZoneArea extends Model
{
    use UsesCountryDatabase;
}
