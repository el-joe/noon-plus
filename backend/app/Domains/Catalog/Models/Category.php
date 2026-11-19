<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Http\Controllers\CatalogController;
use App\Domains\Countries\Support\CountryContext;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[UseFactory(CategoryFactory::class)]
#[Fillable(['parent_id', 'slug', 'name_en', 'name_ar', 'description_en', 'description_ar', 'image_path', 'size_guide_template_id', 'commission_rate', 'return_window_days', 'requires_contract', 'requires_certification', 'is_active', 'sort_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsCountryActivity, UsesCountryDatabase;

    /**
     * WP11: the public category tree is cached per country and locale; any change forgets it.
     */
    protected static function booted(): void
    {
        $forget = function (): void {
            $country = app(CountryContext::class)->has() ? app(CountryContext::class)->current()->code : null;

            if ($country !== null) {
                foreach (['en', 'ar'] as $locale) {
                    Cache::forget(CatalogController::treeCacheKey($country, $locale));
                }
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'commission_rate' => 'decimal:2',
            'requires_contract' => 'boolean',
            'requires_certification' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Attributes sellers fill in and shoppers filter by (wave 2).
     *
     * @return HasMany<CategoryAttribute, $this>
     */
    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<SizeGuideTemplate, $this>
     */
    public function sizeGuide(): BelongsTo
    {
        return $this->belongsTo(SizeGuideTemplate::class, 'size_guide_template_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * This category followed by its parents, nearest first.
     *
     * @return array<int, Category>
     */
    public function lineage(): array
    {
        $lineage = [];
        $category = $this;

        while ($category !== null && count($lineage) < 10) {
            $lineage[] = $category;
            $category = $category->parent;
        }

        return $lineage;
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
