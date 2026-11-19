<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\CardOffers;
use App\Domains\Catalog\Support\ProductBadges;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product as shown on a product card (listing pages, carousels; design
 * card: badge, quick add, rating chip, price + was-price, delivery chip).
 *
 * Offer facts are read from optional attributes on the product — select
 * them in the listing query, or call {@see CardOffers::attach()} on the page
 * of products first (one query for all of them); a product without them
 * is looked up on its own (one query):
 * `best_price`, `best_listing_id`, `best_regular_price`, `best_previous_price`,
 * `best_handling_days`, `best_fulfilment`, `offers_count`, `has_live_deal`.
 * {@see self::collection()} attaches them for the whole collection.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        if (is_iterable($resource)) {
            CardOffers::attach($resource);
        }

        return parent::collection($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        CardOffers::attach([$this->resource]);

        $price = $this->best_price !== null ? (string) $this->best_price : null;
        $was = CardOffers::wasPrice($price, $this->best_regular_price, $this->best_previous_price);
        $deal = (bool) $this->has_live_deal || ($this->best_regular_price !== null && $price !== null && bccomp((string) $this->best_regular_price, $price, 4) > 0);

        return [
            'slug' => $this->slug,
            'title' => $this->title(),
            'brand' => $this->brand?->name,
            'category' => $this->category?->slug,
            'price' => MoneyResource::make($price),
            'was_price' => MoneyResource::make($was),
            'discount_percent' => CardOffers::discountPercent($price, $was),
            'listing_id' => $this->best_listing_id !== null ? (int) $this->best_listing_id : null,
            'offers_count' => (int) $this->offers_count,
            'delivery' => CardOffers::delivery($this->best_handling_days !== null ? (int) $this->best_handling_days : null, $this->best_fulfilment),
            'fulfilment_mode' => $this->fulfilment_mode,
            'image' => $this->primaryImage()?->toPublicArray(),
            'rating' => ['average' => $this->rating_average, 'count' => (int) $this->rating_count],
            'badges' => ProductBadges::forCard($this->resource, $deal),
        ];
    }
}
