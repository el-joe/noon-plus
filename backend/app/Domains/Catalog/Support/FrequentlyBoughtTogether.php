<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * "Frequently bought together" (QC-043, QC-057 / QC-064 PDP): other
 * products that were in the same orders as this one in the last
 * `nawy.discovery.fbt_window_days` days — orders that were paid (or, for
 * cash on delivery, delivered), shipments not cancelled, returned or
 * refunded — ranked by how many orders shared them (at least
 * `fbt_min_orders`).
 */
class FrequentlyBoughtTogether
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @return array<int, int> product id => orders shared, best first
     */
    public function for(Product $product, int $limit = 4): array
    {
        $db = DB::connection($this->countryContext->connectionName());
        $paid = array_values(array_map(fn (PaymentStatus $status): string => $status->value, array_filter(PaymentStatus::cases(), fn (PaymentStatus $status): bool => $status->isSuccessful())));
        $lines = fn () => $db->table('order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->join('listings', 'listings.id', '=', 'order_items.listing_id')
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->where('orders.placed_at', '>=', now()->subDays((int) config('nawy.discovery.fbt_window_days')))
            ->whereNotIn('sub_orders.status', ['cancelled', 'returned', 'refunded'])
            ->where(fn ($query) => $query->whereIn('orders.payment_status', $paid)->orWhereIn('sub_orders.status', ['delivered', 'completed']));

        $orders = $lines()->where('product_variants.product_id', $product->id)->select('orders.id');

        return $lines()
            ->whereIn('orders.id', $orders)
            ->where('product_variants.product_id', '!=', $product->id)
            ->groupBy('product_variants.product_id')
            ->havingRaw('count(distinct orders.id) >= ?', [max(1, (int) config('nawy.discovery.fbt_min_orders'))])
            ->orderByRaw('count(distinct orders.id) desc')
            ->orderBy('product_variants.product_id')
            ->limit($limit * 3)
            ->selectRaw('product_variants.product_id, count(distinct orders.id) as shared')
            ->pluck('shared', 'product_id')
            ->map(fn ($shared): int => (int) $shared)
            ->all();
    }
}
