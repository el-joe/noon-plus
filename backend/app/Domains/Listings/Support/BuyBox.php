<?php

namespace App\Domains\Listings\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Chooses which seller wins a product page (buy box): the lowest price among
 * active listings with stock, then the better-rated seller, then the oldest
 * listing. The other sellable offers appear as "other offers".
 */
class BuyBox
{
    /**
     * Listings a customer can buy right now.
     *
     * @return Builder<Listing>
     */
    public function sellableListings(): Builder
    {
        return Listing::query()
            ->where('listings.status', ListingStatus::Active)
            ->whereRaw('(select coalesce(sum(m.quantity), 0) from inventory_movements m where m.listing_id = listings.id) > 0');
    }

    /**
     * Sellable offers for a product, best first.
     *
     * @return Collection<int, Listing>
     */
    public function offersFor(Product $product): Collection
    {
        return $this->sellableListings()
            ->select('listings.*')
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('vendors', 'vendors.id', '=', 'listings.vendor_id')
            ->where('product_variants.product_id', $product->id)
            ->withAvailableQuantity()
            ->with(['vendor', 'variant'])
            ->orderByRaw('coalesce(listings.sale_price, listings.price) asc')
            ->orderByDesc('vendors.rating')
            ->orderBy('listings.id')
            ->get();
    }

    /**
     * Correlated subquery: `products.id` has at least one sellable offer.
     */
    public function hasSellableOfferSubquery(): QueryBuilder
    {
        return $this->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->selectRaw('1')
            ->toBase();
    }

    /**
     * Correlated subquery: the buy-box price of `products.id`.
     */
    public function bestPriceSubquery(): QueryBuilder
    {
        return $this->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->selectRaw('min(coalesce(listings.sale_price, listings.price))')
            ->toBase();
    }
}
