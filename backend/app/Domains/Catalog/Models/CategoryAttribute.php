<?php

namespace App\Domains\Catalog\Models;

use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attribute of a category (FR-CAT-1): sellers fill it in on their
 * products (`text`, `number`, `select` with options) and, when filterable,
 * shoppers filter the category by it.
 */
#[Fillable(['category_id', 'key', 'label_en', 'label_ar', 'type', 'options', 'is_required', 'is_filterable', 'sort_order'])]
class CategoryAttribute extends Model
{
    use LogsCountryActivity, UsesCountryDatabase;

    public const TYPES = ['text', 'number', 'select', 'boolean'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'is_filterable' => 'boolean'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
