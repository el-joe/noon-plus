<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Product;
use App\Domains\Listings\Support\BuyBox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Product cards for a list of product ids in a given order (recently
 * viewed, frequently bought together, followed stores…): one query with
 * the buy-box price, active products only, the order kept.
 */
class ProductCards
{
    public function __construct(private BuyBox $buyBox) {}

    /**
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product> keyed by id, with `best_price`
     */
    public function load(array $productIds): Collection
    {
        if ($productIds === []) {
            return new Collection;
        }

        return Product::query()
            ->whereIn('products.id', $productIds)
            ->where('products.status', 'active')
            ->addSelect(['products.*', 'best_price' => $this->buyBox->bestPriceSubquery()])
            ->with(['brand', 'category', 'media'])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function cards(array $productIds, Request $request): array
    {
        $products = $this->load($productIds);

        return collect($productIds)
            ->filter(fn (int $id): bool => $products->has($id))
            ->map(fn (int $id): array => (new ProductCardResource($products[$id]))->resolve($request) + ['in_stock' => $products[$id]->best_price !== null])
            ->values()
            ->all();
    }
}
