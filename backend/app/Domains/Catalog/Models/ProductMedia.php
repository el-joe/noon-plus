<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product image (G-W1): vendor-managed, stored on the public disk, ordered
 * by `sort_order`, with EN/AR alt text. A variant image belongs to one
 * variant; a product image (no variant) shows for every variant.
 */
#[Fillable(['product_id', 'product_variant_id', 'vendor_id', 'path', 'alt_en', 'alt_ar', 'width', 'height', 'sort_order'])]
class ProductMedia extends Model
{
    use UsesCountryDatabase;

    public const DISK = 'public';

    protected $table = 'product_media';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function alt(): ?string
    {
        return app()->getLocale() === 'ar' ? ($this->alt_ar ?: $this->alt_en) : ($this->alt_en ?: $this->alt_ar);
    }

    /**
     * @return array{id: int, url: string, alt: string|null, alt_en: string|null, alt_ar: string|null, variant_sku: string|null, width: int|null, height: int|null, sort_order: int}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'alt' => $this->alt(),
            'alt_en' => $this->alt_en,
            'alt_ar' => $this->alt_ar,
            'variant_sku' => $this->product_variant_id === null ? null : $this->variant?->sku,
            'width' => $this->width,
            'height' => $this->height,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
