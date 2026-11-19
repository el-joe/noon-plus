<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Support\SearchText;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalogue product (one page on the storefront). Sellers attach offers
 * (listings) to its variants.
 */
#[UseFactory(ProductFactory::class)]
#[Fillable([
    'category_id', 'brand_id', 'slug', 'title_en', 'title_ar', 'model_number', 'description_en', 'description_ar',
    'highlights', 'highlights_ar', 'specifications', 'specifications_ar', 'status', 'fulfilment_mode', 'lead_days', 'size_guide_template_id',
    'rating_average', 'rating_count',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsCountryActivity, UsesCountryDatabase;

    /**
     * Keep the search index (`search_text`) in step with the product (QC-015).
     */
    protected static function booted(): void
    {
        static::saved(fn (Product $product) => SearchText::refresh($product->id));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'highlights_ar' => 'array',
            'specifications' => 'array',
            'specifications_ar' => 'array',
            'rating_average' => 'decimal:2',
            'rating_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * All images of the product and its variants, in display order.
     *
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The main image for a card or a line: the variant's first image, else
     * the first product-level image, else the first image at all.
     */
    public function primaryImage(?int $variantId = null): ?ProductMedia
    {
        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();

        return ($variantId !== null ? $media->firstWhere('product_variant_id', $variantId) : null)
            ?? $media->firstWhere('product_variant_id', null)
            ?? $media->first();
    }

    /**
     * The product page copy in one language (G-W2): description, highlights
     * (list of strings) and specifications (list of {label, value}). Arabic
     * falls back to English when it has not been written yet.
     *
     * @return array{description: string|null, highlights: array<int, string>, specifications: array<int, array{label: string, value: string}>}
     */
    public function content(string $locale): array
    {
        $arabic = $locale === 'ar';

        return [
            'description' => $arabic ? ($this->description_ar ?: $this->description_en) : ($this->description_en ?: $this->description_ar),
            'highlights' => self::highlightList($arabic && filled($this->highlights_ar) ? $this->highlights_ar : $this->highlights),
            'specifications' => self::specificationRows($arabic && filled($this->specifications_ar) ? $this->specifications_ar : $this->specifications),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function highlightList(mixed $highlights): array
    {
        return collect(is_array($highlights) ? $highlights : [])
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item)
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * Accepts a {label: value} map or a list of {label|name, value} rows.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function specificationRows(mixed $specifications): array
    {
        if (! is_array($specifications)) {
            return [];
        }

        $rows = array_is_list($specifications)
            ? array_map(fn (mixed $row): array => is_array($row) ? ['label' => (string) ($row['label'] ?? $row['name'] ?? ''), 'value' => (string) ($row['value'] ?? '')] : ['label' => '', 'value' => ''], $specifications)
            : array_map(fn (string|int $label, mixed $value): array => ['label' => (string) $label, 'value' => is_scalar($value) ? (string) $value : (string) json_encode($value)], array_keys($specifications), $specifications);

        return array_values(array_filter($rows, fn (array $row): bool => $row['label'] !== ''));
    }

    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }
}
