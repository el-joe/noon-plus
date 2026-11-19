<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Listings\Models\Listing;

/**
 * One product-card shape for a listing wherever a listing is shown outside
 * the catalogue search (G-W14): page-builder product blocks, flash-sale
 * deals, live-stream products, creator picks and referral picks. Same
 * `image`, `rating`, `was_price`, `discount_percent`, `delivery` and
 * `badges` as {@see ProductCardResource}, from this listing's own offer.
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
     * @return array{listing_id: int, slug: string|null, title: string|null, brand: string|null, price: array{amount: string, currency: string}|null, was_price: array{amount: string, currency: string}|null, discount_percent: int|null, delivery: array{express: bool, key: string}|null, image: array<string, mixed>|null, rating: array{average: string|null, count: int}, badges: array<int, array{key: string, label: string}>}
     */
    public static function card(Listing $listing, ?string $price = null): array
    {
        $product = $listing->variant?->product;
        $amount = $price ?? $listing->effectivePrice();
        $amount = $amount !== null ? (string) $amount : null;
        $was = CardOffers::wasPrice($amount, $listing->price !== null ? (string) $listing->price : null, $listing->previous_price !== null ? (string) $listing->previous_price : null);

        return [
            'listing_id' => $listing->id,
            'slug' => $product?->slug,
            'title' => $product?->title(),
            'brand' => $product?->brand?->name,
            'price' => MoneyResource::make($amount),
            'was_price' => MoneyResource::make($was),
            'discount_percent' => CardOffers::discountPercent($amount, $was),
            'delivery' => CardOffers::delivery($listing->handling_days !== null ? (int) $listing->handling_days : null, $listing->fulfilment),
            'image' => $product?->primaryImage($listing->product_variant_id)?->toPublicArray(),
            'rating' => ['average' => $product?->rating_average, 'count' => (int) ($product?->rating_count ?? 0)],
            'badges' => $product !== null ? ProductBadges::forCard($product, $was !== null && bccomp((string) $listing->price, (string) $amount, 4) > 0) : [],
        ];
    }
}
