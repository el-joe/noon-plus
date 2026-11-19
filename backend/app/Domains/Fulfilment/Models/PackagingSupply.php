<?php

namespace App\Domains\Fulfilment\Models;

use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An item of the country's packaging catalogue (SRS §4.1.3): boxes,
 * mailers, tape… sold to stores at a unit cost in the country currency.
 */
#[Fillable(['sku', 'name_en', 'name_ar', 'description_en', 'description_ar', 'unit_cost', 'min_quantity', 'max_quantity', 'is_active', 'sort_order'])]
class PackagingSupply extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_quantity' => 'integer', 'max_quantity' => 'integer', 'sort_order' => 'integer'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
