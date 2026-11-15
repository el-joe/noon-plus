<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Listings\Models\Listing;

/**
 * One product-card shape for a listing wherever a listing is shown outside
 * the catalogue search (G-W14): page-builder product blocks, flash-sale
 * deals, live-stream products, creator picks and referral picks. Same
 * `image` and `rating` as {@see ProductCardResource}.
 */
class ListingCards
{
    /**
     * Relations to eager-load on a listing query before calling {@see self::card()}.
     *
     * @return array<int, string>
     */
    public static function relations(string $prefix = ''): array
    {
        return [$prefix.'variant.product.media.variant', $prefix.'variant.product.brand'];
    }

    /**
     * @return array{listing_id: int, slug: string|null, title: string|null, brand: string|null, price: array{amount: string, currency: string}|null, image: array<string, mixed>|null, rating: array{average: string|null, count: int}}
     */
    public static function card(Listing $listing, ?string $price = null): array
    {
        $product = $listing->variant?->product;

        return [
            'listing_id' => $listing->id,
            'slug' => $product?->slug,
            'title' => $product?->title(),
            'brand' => $product?->brand?->name,
            'price' => MoneyResource::make($price ?? $listing->effectivePrice()),
            'image' => $product?->primaryImage($listing->product_variant_id)?->toPublicArray(),
            'rating' => ['average' => $product?->rating_average, 'count' => (int) ($product?->rating_count ?? 0)],
        ];
    }
}
