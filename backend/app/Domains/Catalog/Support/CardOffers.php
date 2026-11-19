<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Support\BuyBox;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Offer facts a product card needs beyond the product row (design card:
 * was-price, discount, delivery chip, "deal" badge, quick add), loaded for
 * many products in ONE query over their sellable offers and set as
 * attributes on each product:
 *
 * - `best_price`          buy-box price, coalesce(sale_price, price) (kept when already selected)
 * - `best_listing_id`     the buy-box listing (quick add)
 * - `best_regular_price`  the buy-box listing's regular `price`
 * - `best_previous_price` the buy-box listing's `previous_price` (price drop)
 * - `best_handling_days`  handling days of the fastest offer
 * - `best_fulfilment`     `fbn` / `fbm` of the fastest offer
 * - `offers_count`        sellable offers
 * - `has_live_deal`       one of its offers is in a running flash sale
 *
 * Listing builders may select the same names themselves; {@see self::attach()}
 * skips products that already have `offers_count`.
 */
class CardOffers
{
    public const ATTRIBUTES = ['best_price', 'best_listing_id', 'best_regular_price', 'best_previous_price', 'best_handling_days', 'best_fulfilment', 'offers_count', 'has_live_deal'];

    /**
     * @param  iterable<int, Product>|AbstractPaginator  $products
     */
    public static function attach(iterable|AbstractPaginator $products): void
    {
        $missing = [];

        foreach ($products instanceof AbstractPaginator ? $products->items() : $products as $product) {
            if ($product instanceof Product && ! self::loaded($product)) {
                $missing[$product->id] ??= [];
                $missing[$product->id][] = $product;
            }
        }

        if ($missing === []) {
            return;
        }

        $rows = app(BuyBox::class)->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('vendors', 'vendors.id', '=', 'listings.vendor_id')
            ->whereIn('product_variants.product_id', array_keys($missing))
            ->orderByRaw('coalesce(listings.sale_price, listings.price) asc')
            ->orderByDesc('vendors.rating')
            ->orderBy('listings.id')
            ->toBase()
            ->get(['listings.id', 'product_variants.product_id', 'listings.price', 'listings.sale_price', 'listings.previous_price', 'listings.handling_days', 'listings.fulfilment'])
            ->groupBy('product_id');
        $deals = app(FlashSalePricing::class)->activeDeals($rows->flatten(1)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        foreach ($missing as $productId => $copies) {
            $offers = $rows->get($productId, collect());
            $buyBox = $offers->first();
            $fastest = $offers->sortBy(fn (object $offer): array => [(int) $offer->handling_days, $offer->fulfilment === 'fbn' ? 0 : 1])->first();
            $values = [
                'best_listing_id' => $buyBox?->id !== null ? (int) $buyBox->id : null,
                'best_regular_price' => $buyBox?->price !== null ? (string) $buyBox->price : null,
                'best_previous_price' => $buyBox?->previous_price !== null ? (string) $buyBox->previous_price : null,
                'best_handling_days' => $fastest?->handling_days !== null ? (int) $fastest->handling_days : null,
                'best_fulfilment' => $fastest?->fulfilment,
                'offers_count' => $offers->count(),
                'has_live_deal' => $offers->contains(fn (object $offer): bool => $deals->has((int) $offer->id)),
            ];
            $bestPrice = $buyBox === null ? null : (string) ($buyBox->sale_price ?? $buyBox->price);

            foreach ($copies as $product) {
                if (! array_key_exists('best_price', $product->getAttributes())) {
                    $product->setAttribute('best_price', $bestPrice);
                }

                foreach ($values as $key => $value) {
                    $product->setAttribute($key, $value);
                }
            }
        }
    }

    public static function loaded(Product $product): bool
    {
        return array_key_exists('offers_count', $product->getAttributes());
    }

    /**
     * The struck-through price: the regular price while on sale, else the
     * price before a drop.
     */
    public static function wasPrice(?string $price, ?string $regular, ?string $previous): ?string
    {
        if ($price === null) {
            return null;
        }

        foreach ([$regular, $previous] as $candidate) {
            if ($candidate !== null && is_numeric($candidate) && bccomp((string) $candidate, (string) $price, 4) > 0) {
                return (string) $candidate;
            }
        }

        return null;
    }

    public static function discountPercent(?string $price, ?string $was): ?int
    {
        if ($price === null || $was === null || bccomp($was, '0', 4) <= 0) {
            return null;
        }

        $percent = (int) round(((float) $was - (float) $price) * 100 / (float) $was);

        return $percent >= 1 ? $percent : null;
    }

    /**
     * Delivery chip of the fastest offer: express (Nawy-fulfilled or ships
     * today) and when it arrives.
     *
     * @return array{express: bool, key: string}|null
     */
    public static function delivery(?int $handlingDays, ?string $fulfilment): ?array
    {
        if ($handlingDays === null) {
            return null;
        }

        return [
            'express' => $fulfilment === 'fbn' || $handlingDays === 0,
            'key' => match (true) {
                $handlingDays <= 0 => 'today',
                $handlingDays === 1 => 'tomorrow',
                $handlingDays === 2 => 'two_days',
                default => 'later',
            },
        ];
    }
}
