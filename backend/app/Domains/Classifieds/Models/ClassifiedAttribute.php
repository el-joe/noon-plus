<?php

namespace App\Domains\Classifieds\Models;

use App\Domains\Classifieds\Enums\AttributeType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One field of a category attribute schema (type, options, unit, range, required, filterable).
 */
#[Fillable(['classified_category_id', 'key', 'label_en', 'label_ar', 'type', 'options', 'unit', 'min_value', 'max_value', 'is_required', 'is_filterable', 'sort_order'])]
class ClassifiedAttribute extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_required' => false, 'is_filterable' => false, 'sort_order' => 0];

    protected $table = 'classified_attributes';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
        ];
    }
}
