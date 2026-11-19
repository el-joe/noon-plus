<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryContext;
use Illuminate\Support\Facades\DB;

/**
 * Bestseller rankings (SRS FR-CAT-3; QC-015): units sold per product in the
 * last 30 days — order items of sub-orders that were not cancelled or
 * refunded — written to `products.units_sold_30d`, and rank 1…n
 * (`bestseller_rank`) among products that sold anything; the others are
 * unranked. The storefront sorts by it with `sort=bestselling`.
 */
class BestsellerRankings
{
    public const WINDOW_DAYS = 30;

    public function __construct(private CountryContext $countryContext) {}

    public function rank(): int
    {
        $db = DB::connection($this->countryContext->connectionName());
        $sold = $db->table('order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->join('listings', 'listings.id', '=', 'order_items.listing_id')
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->where('orders.placed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotIn('sub_orders.status', ['cancelled', 'refunded'])
            ->groupBy('product_variants.product_id')
            ->orderByDesc('units')->orderBy('product_variants.product_id')
            ->selectRaw('product_variants.product_id, sum(order_items.quantity) as units')
            ->pluck('units', 'product_id');

        $db->transaction(function () use ($sold): void {
            Product::query()->where(fn ($query) => $query->where('units_sold_30d', '>', 0)->orWhereNotNull('bestseller_rank'))
                ->update(['units_sold_30d' => 0, 'bestseller_rank' => null]);
            $rank = 0;

            foreach ($sold as $productId => $units) {
                Product::query()->whereKey($productId)->update(['units_sold_30d' => (int) $units, 'bestseller_rank' => ++$rank]);
            }
        });

        return $sold->count();
    }
}
