<?php

namespace App\Domains\Warranty\Models;

use App\Domains\Catalog\Models\Category;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\WarrantyPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extended warranty plan sold at checkout for products in a category (or its
 * child categories). Price per unit, VAT-inclusive; platform revenue.
 */
#[UseFactory(WarrantyPlanFactory::class)]
#[Fillable(['category_id', 'name_en', 'name_ar', 'duration_months', 'price', 'is_active', 'sort_order'])]
class WarrantyPlan extends Model
{
    /** @use HasFactory<WarrantyPlanFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
