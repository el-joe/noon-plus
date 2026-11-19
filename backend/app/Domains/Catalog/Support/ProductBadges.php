<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;

/**
 * Merchandising badges (QC-057, Figma PDP / cards): `bestseller` (weekly
 * rank in the top N), `new` (added in the last N days) and `top_rated`
 * and `trending` (selling fast — `units_sold_30d` at the configured
 * threshold — without being a ranked bestseller) come from the product
 * alone, so cards can show them for free; `deal` (sale or flash price on
 * the buy box) shows on cards when the caller knows it ({@see CardOffers})
 * and on the PDP, which also adds `fulfilled_by_nawy` (the buy box ships
 * from a Nawy warehouse, fbn).
 */
class ProductBadges
{
    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function forCard(Product $product, bool $deal = false): array
    {
        $keys = [];
        $bestseller = $product->bestseller_rank !== null && (int) $product->bestseller_rank <= (int) config('nawy.discovery.bestseller_badge_top');

        if ($bestseller) {
            $keys[] = 'bestseller';
        }

        if ($product->created_at !== null && $product->created_at->greaterThanOrEqualTo(now()->subDays((int) config('nawy.discovery.new_badge_days')))) {
            $keys[] = 'new';
        }

        if ((int) $product->rating_count >= (int) config('nawy.discovery.top_rated_min_reviews')
            && $product->rating_average !== null && bccomp((string) $product->rating_average, (string) config('nawy.discovery.top_rated_min_average'), 2) >= 0) {
            $keys[] = 'top_rated';
        }

        if (! $bestseller && (int) $product->units_sold_30d >= (int) config('nawy.discovery.trending_min_units', 25)) {
            $keys[] = 'trending';
        }

        if ($deal) {
            $keys[] = 'deal';
        }

        return self::labelled($keys);
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function forProductPage(Product $product, ?Listing $buyBox, ?FlashSaleSubmission $flashDeal): array
    {
        $keys = array_column(self::forCard($product), 'key');

        if ($buyBox !== null && ($flashDeal !== null || $buyBox->sale_price !== null)) {
            $keys[] = 'deal';
        }

        if ($buyBox?->fulfilment === 'fbn') {
            $keys[] = 'fulfilled_by_nawy';
        }

        return self::labelled($keys);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array{key: string, label: string}>
     */
    private static function labelled(array $keys): array
    {
        $labels = [
            'bestseller' => __('Best seller'),
            'new' => __('New arrival'),
            'top_rated' => __('Top rated'),
            'trending' => __('Trending'),
            'deal' => __('Deal'),
            'fulfilled_by_nawy' => __('Fulfilled by Nawy'),
        ];

        return array_map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key]], $keys);
    }
}
