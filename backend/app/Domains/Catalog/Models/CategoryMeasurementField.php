<?php

namespace App\Domains\Catalog\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A measurement asked for made-to-order products of a category (inherited by sub-categories).
 */
#[Fillable(['category_id', 'key', 'label_en', 'label_ar', 'unit', 'min_value', 'max_value', 'is_required', 'sort_order'])]
class CategoryMeasurementField extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_required' => true, 'unit' => 'cm', 'sort_order' => 0];

    protected $table = 'category_measurement_fields';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'is_required' => 'boolean',
        ];
    }
}
