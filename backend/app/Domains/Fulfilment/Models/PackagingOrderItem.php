<?php

namespace App\Domains\Fulfilment\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a packaging order with the supply's name and unit cost as ordered.
 */
#[Fillable(['packaging_order_id', 'packaging_supply_id', 'sku', 'name_en', 'name_ar', 'unit_cost', 'quantity', 'line_total'])]
class PackagingOrderItem extends Model
{
    use UsesCountryDatabase;

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
