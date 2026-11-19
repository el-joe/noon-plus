<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\ProductBadges;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product as shown on a product card (listing pages, carousels).
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title(),
            'brand' => $this->brand?->name,
            'category' => $this->category?->slug,
            'price' => MoneyResource::make($this->best_price),
            'fulfilment_mode' => $this->fulfilment_mode,
            'image' => $this->primaryImage()?->toPublicArray(),
            'rating' => ['average' => $this->rating_average, 'count' => (int) $this->rating_count],
            'badges' => ProductBadges::forCard($this->resource),
        ];
    }
}
