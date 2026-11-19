<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Support\SearchText;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ProductVariantFactory::class)]
#[Fillable(['product_id', 'sku', 'barcode', 'option_values', 'weight_grams'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, UsesCountryDatabase;

    /**
     * SKUs and barcodes are part of the product's search index (QC-015).
     */
    protected static function booted(): void
    {
        static::saved(fn (ProductVariant $variant) => SearchText::refresh($variant->product_id));
        static::deleted(fn (ProductVariant $variant) => SearchText::refresh($variant->product_id));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['option_values' => 'array'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
