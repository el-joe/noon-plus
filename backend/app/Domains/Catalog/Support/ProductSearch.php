<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\InventoryMovement;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\BuyBox;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Http\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Product listing filters and facets (G-W4). Every filter narrows the
 * set of active products with a sellable offer; each facet is counted
 * with every other filter applied but its own (so picking one brand still
 * shows the other brands' counts).
 *
 * Filters: q, category (slug, all descendants), brand (slugs, comma
 * separated), seller (vendor slugs), min_price / max_price (buy-box price),
 * rating (minimum average), attr[key] (variant option values, comma separated),
 * fulfilment (fbm, fbn, supermall = sold by the brand's own store),
 * arrives (hour | tomorrow | two_days, from the offer's fulfilment and
 * handling days), condition (new, refurbished, used), grade (A, B, C),
 * deals, price_drop (lowered in the last 30 days), new_arrivals (added in
 * the last 30 days).
 * `store` (a vendor id, set by the seller storefront, never from the
 * request) scopes products, offers and every facet to that one store
 * (RT-005); unlike `seller` it is never left out of a facet.
 *
 * Performance (category page, 5k products / 30k offers per country): the
 * per-offer stock sum is not evaluated per row; the few active offers
 * without stock are read once per request and excluded by id.
 */
class ProductSearch
{
    public const ATTRIBUTE_KEY = '/^[A-Za-z0-9_]{1,40}$/';

    /** Arrives-by buckets, fastest first (each includes the faster ones). */
    public const ARRIVES = ['hour', 'tomorrow', 'two_days'];

    public const FULFILMENT = ['supermall', 'fbn', 'fbm'];

    public const GRADES = ['A', 'B', 'C'];

    public const RECENT_DAYS = 30;

    /** Above this many stockless offers the correlated stock check is used instead of an id list. */
    private const STOCKLESS_LIMIT = 3000;

    private const MEMO = 'product_search.stockless';

    public function __construct(private BuyBox $buyBox) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function query(array $filters, ?string $except = null): Builder
    {
        $use = fn (string $filter): mixed => $filter === $except ? null : ($filters[$filter] ?? null);
        $bestPrice = $this->bestPriceSubquery();

        return Product::query()
            ->where('products.status', 'active')
            ->whereExists($this->hasSellableOfferSubquery())
            ->when($filters['store'] ?? null, fn (Builder $query, int $vendorId) => $query->whereExists($this->offerWhere(fn ($listings) => $listings->where('listings.vendor_id', $vendorId))))
            ->when($use('q'), fn (Builder $query, string $term) => $query->where(
                fn (Builder $search) => $search->where('products.title_en', 'like', LikeSearch::contains($term))->orWhere('products.title_ar', 'like', LikeSearch::contains($term))
                    ->when(SearchText::normalise($term) !== '', fn (Builder $index) => $index->orWhere('products.search_text', 'like', LikeSearch::contains(SearchText::normalise($term))))
            ))
            ->when($use('category'), fn (Builder $query, string $slug) => $query->whereIn('products.category_id', $this->categoryAndDescendantIds($slug)))
            ->when(self::slugs($use('brand')), fn (Builder $query, array $slugs) => $query->whereIn('products.brand_id', Brand::query()->select('id')->whereIn('slug', $slugs)))
            ->when(self::slugs($use('seller')), fn (Builder $query, array $slugs) => $query->whereExists(
                $this->offerWhere(fn ($listings) => $listings->whereIn('listings.vendor_id', Vendor::query()->select('id')->whereIn('slug', $slugs)))
            ))
            ->when($except !== 'price' && isset($filters['min_price']), fn (Builder $query) => $query->where($bestPrice, '>=', $filters['min_price']))
            ->when($except !== 'price' && isset($filters['max_price']), fn (Builder $query) => $query->where(clone $bestPrice, '<=', $filters['max_price']))
            ->when($use('rating'), fn (Builder $query, int|string $rating) => $query->where('products.rating_average', '>=', (int) $rating))
            ->when(is_array($use('attr')) ? $use('attr') : [], function (Builder $query, array $attributes): void {
                foreach ($attributes as $key => $values) {
                    if (! preg_match(self::ATTRIBUTE_KEY, (string) $key) || ($values = self::slugs($values)) === []) {
                        continue;
                    }

                    $query->whereHas('variants', fn (Builder $variant) => $variant->whereIn('option_values->'.$key, $values));
                }
            })
            // QC-027 rail: fulfilled by (fbm / fbn / supermall), arrives by, condition, grade, deals and price drops, on any sellable offer of the product.
            ->when(self::slugs($use('fulfilment')), fn (Builder $query, array $values) => $query->whereExists($this->offerWhere(fn ($listings) => $this->fulfilledBy($listings, $values))))
            ->when(in_array($use('arrives'), self::ARRIVES, true), fn (Builder $query) => $query->whereExists($this->offerWhere(fn ($listings) => $this->arrivesBy($listings, (string) $filters['arrives']))))
            ->when(self::slugs($use('condition')), fn (Builder $query, array $values) => $query->whereExists($this->offerWhere(fn ($listings) => $listings->whereIn('listings.condition', $values))))
            ->when(self::slugs($use('grade')), fn (Builder $query, array $values) => $query->whereExists($this->offerWhere(fn ($listings) => $listings->whereIn('listings.grade', $values))))
            ->when($use('deals'), fn (Builder $query) => $query->whereExists($this->offerWhere(fn ($listings) => $this->onDeal($listings))))
            ->when($use('price_drop'), fn (Builder $query) => $query->whereExists($this->offerWhere(fn ($listings) => $this->priceDropped($listings))))
            ->when($use('new_arrivals'), fn (Builder $query) => $query->where('products.created_at', '>=', now()->subDays(self::RECENT_DAYS)));
    }

    /**
     * Listings a customer can buy right now: the same set as
     * {@see BuyBox::sellableListings()}, without a stock sum per row.
     *
     * @return Builder<Listing>
     */
    public function sellableListings(): Builder
    {
        $stockless = $this->stocklessListingIds();

        if ($stockless === null) {
            return $this->buyBox->sellableListings();
        }

        return Listing::query()->where('listings.status', ListingStatus::Active)
            ->when($stockless !== [], fn (Builder $listings) => $listings->whereNotIn('listings.id', $stockless));
    }

    /**
     * Correlated subquery: `products.id` has at least one sellable offer.
     */
    public function hasSellableOfferSubquery(): QueryBuilder
    {
        return $this->offerWhere(fn () => null);
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

    /**
     * Active listings whose stock (sum of movements) is not positive —
     * normally a short list — read once per HTTP request. Null when there
     * are too many (the correlated stock check is used then).
     *
     * @return array<int, int>|null
     */
    private function stocklessListingIds(): ?array
    {
        $request = app()->bound('request') ? app('request') : null;
        $memo = $request instanceof Request && $request->route() !== null;

        if ($memo && $request->attributes->has(self::MEMO)) {
            return $request->attributes->get(self::MEMO);
        }

        $connection = (new Listing)->getConnectionName();
        $stock = DB::connection($connection)->table((new InventoryMovement)->getTable())
            ->select('listing_id', DB::raw('sum(quantity) as on_hand'))
            ->groupBy('listing_id');
        $ids = DB::connection($connection)->table('listings')
            ->leftJoinSub($stock, 'stock', 'stock.listing_id', '=', 'listings.id')
            ->where('listings.status', ListingStatus::Active->value)
            ->where(fn (QueryBuilder $none) => $none->whereNull('stock.on_hand')->orWhere('stock.on_hand', '<=', 0))
            ->limit(self::STOCKLESS_LIMIT + 1)
            ->pluck('listings.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $ids = count($ids) > self::STOCKLESS_LIMIT ? null : $ids;

        if ($memo) {
            $request->attributes->set(self::MEMO, $ids);
        }

        return $ids;
    }

    /**
     * Correlated: `products.id` has a sellable offer matching `$constraint`.
     *
     * @param  callable(Builder<Listing>): mixed  $constraint
     */
    private function offerWhere(callable $constraint): QueryBuilder
    {
        $listings = $this->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id');
        $constraint($listings);

        return $listings->selectRaw('1')->toBase();
    }

    /**
     * Offers fulfilled by: `fbn` (Nawy warehouse), `fbm` (the seller) and
     * `supermall` (sold direct by the brand's own store: the vendor slug is
     * the brand slug, `{brand}-official` or `{brand}-store`).
     *
     * @param  Builder<Listing>  $listings
     * @param  array<int, string>  $values
     */
    private function fulfilledBy(Builder $listings, array $values): void
    {
        $columns = array_values(array_intersect($values, ['fbm', 'fbn']));
        $supermall = in_array('supermall', $values, true);

        $listings->where(function (Builder $any) use ($columns, $supermall): void {
            if ($columns !== []) {
                $any->orWhereIn('listings.fulfilment', $columns);
            }

            if ($supermall) {
                $any->orWhereExists(self::directFromBrand());
            }

            if ($columns === [] && ! $supermall) {
                $any->whereRaw('0 = 1');
            }
        });
    }

    /**
     * Correlated on `listings` + `products` (the outer rows): the offer's vendor is the product brand's own store.
     */
    private static function directFromBrand(): \Closure
    {
        return fn (QueryBuilder $store) => $store->selectRaw('1')->from('vendors')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereColumn('vendors.id', 'listings.vendor_id')
            ->where(fn (QueryBuilder $slug) => $slug->whereColumn('vendors.slug', 'brands.slug')
                ->orWhereRaw("vendors.slug = concat(brands.slug, '-official')")
                ->orWhereRaw("vendors.slug = concat(brands.slug, '-store')"));
    }

    /**
     * Arrives by: `hour` = Fulfilled by Nawy or ships the same day,
     * `tomorrow` = within one handling day, `two_days` = within two.
     *
     * @param  Builder<Listing>  $listings
     */
    private function arrivesBy(Builder $listings, string $bucket): void
    {
        $days = ['hour' => 0, 'tomorrow' => 1, 'two_days' => 2][$bucket] ?? 2;
        $listings->where(fn (Builder $fast) => $fast->where('listings.fulfilment', 'fbn')->orWhere('listings.handling_days', '<=', $days));
    }

    /**
     * An offer on deal: a sale price under the regular one, or an approved / live flash deal running now.
     *
     * @param  Builder<Listing>  $listings
     */
    private function onDeal(Builder $listings): void
    {
        $listings->where(fn (Builder $deal) => $deal->where(fn (Builder $sale) => $sale->whereNotNull('listings.sale_price')->whereColumn('listings.sale_price', '<', 'listings.price'))
            ->orWhereExists(fn ($flash) => $flash->selectRaw('1')->from('flash_sale_submissions')
                ->join('flash_sale_events', 'flash_sale_events.id', '=', 'flash_sale_submissions.flash_sale_event_id')
                ->whereColumn('flash_sale_submissions.listing_id', 'listings.id')
                ->whereIn('flash_sale_submissions.status', ['approved', 'live'])
                ->where('flash_sale_events.starts_at', '<=', now())->where('flash_sale_events.ends_at', '>', now())));
    }

    /**
     * Price drop: the offer was lowered from `previous_price` in the last 30 days and still costs less.
     *
     * @param  Builder<Listing>  $listings
     */
    private function priceDropped(Builder $listings): void
    {
        $listings->whereNotNull('listings.previous_price')
            ->where('listings.price_dropped_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereRaw('coalesce(listings.sale_price, listings.price) < listings.previous_price');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters): array
    {
        // Offer facets whose own filter is not set all count the offers of the same products: read those offers once.
        $offers = null;
        $shared = function () use (&$offers, $filters) {
            return $offers ??= $this->offerRows($filters);
        };
        $set = fn (string $filter): bool => ! empty($filters[$filter]);

        return [
            'brands' => $this->brandFacet($filters),
            'sellers' => $set('seller') ? $this->sellerFacet($filters) : $this->sellerRows($shared()),
            'price' => $this->priceFacet($filters),
            'ratings' => $this->ratingFacet($filters),
            'attributes' => $this->attributeFacet($filters),
            'fulfilment' => $set('fulfilment') ? $this->fulfilmentFacet($filters) : $this->fulfilmentRows($shared()),
            'arrives' => $set('arrives') ? $this->arrivesFacet($filters) : $this->arrivesRows($shared()),
            'conditions' => $set('condition') ? $this->offerFacet($filters, 'condition') : self::countRows($shared(), 'condition'),
            'grades' => array_values(array_filter($set('grade') ? $this->offerFacet($filters, 'grade') : self::countRows($shared(), 'grade'), fn (array $row): bool => in_array($row['value'], self::GRADES, true))),
            'deals' => $this->query($filters, 'deals')->whereExists($this->offerWhere(fn ($listings) => $this->onDeal($listings)))->count(),
            'price_drop' => $this->query($filters, 'price_drop')->whereExists($this->offerWhere(fn ($listings) => $this->priceDropped($listings)))->count(),
            'new_arrivals' => $this->query($filters, 'new_arrivals')->where('products.created_at', '>=', now()->subDays(self::RECENT_DAYS))->count(),
            'categories' => $this->categoryFacet($filters),
        ];
    }

    /**
     * The sellable offers (of the store, when scoped) of the products matching every filter.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, \stdClass>
     */
    private function offerRows(array $filters): Collection
    {
        return $this->storeOffers($filters)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('products as offer_products', 'offer_products.id', '=', 'product_variants.product_id')
            ->whereIntegerInRaw('product_variants.product_id', $this->query($filters)->pluck('products.id')->all())
            ->toBase()
            ->get(['listings.vendor_id', 'listings.fulfilment', 'listings.condition', 'listings.grade', 'listings.handling_days', 'product_variants.product_id', 'offer_products.brand_id']);
    }

    /**
     * Distinct products per value of an offer column, most first.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{value: string, count: int}>
     */
    private static function countRows(Collection $rows, string $column): array
    {
        $products = [];

        foreach ($rows as $row) {
            if ($row->{$column} !== null) {
                $products[(string) $row->{$column}][$row->product_id] = true;
            }
        }

        $out = array_map(fn (string|int $value, array $ids): array => ['value' => (string) $value, 'count' => count($ids)], array_keys($products), $products);

        usort($out, fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{slug: string, name: string, rating: string|null, count: int}>
     */
    private function sellerRows(Collection $rows): array
    {
        $counts = collect(self::countRows($rows, 'vendor_id'))->pluck('count', 'value');

        return Vendor::query()->whereIn('id', $counts->keys())->orderBy('store_name')->get()
            ->map(fn (Vendor $vendor): array => ['slug' => $vendor->slug, 'name' => $vendor->store_name, 'rating' => $vendor->rating, 'count' => (int) $counts[(string) $vendor->id]])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{value: string, count: int}>
     */
    private function fulfilmentRows(Collection $rows): array
    {
        $vendors = Vendor::query()->whereIn('id', $rows->pluck('vendor_id')->unique()->values())->pluck('slug', 'id');
        $brands = Brand::query()->whereIn('id', $rows->pluck('brand_id')->filter()->unique()->values())->pluck('slug', 'id');
        $direct = [];

        foreach ($rows as $row) {
            $brand = $row->brand_id === null ? null : ($brands[$row->brand_id] ?? null);

            if ($brand !== null && in_array($vendors[$row->vendor_id] ?? null, [$brand, $brand.'-official', $brand.'-store'], true)) {
                $direct[$row->product_id] = true;
            }
        }

        $counts = self::countRows($rows, 'fulfilment');

        return $direct === [] ? $counts : [['value' => 'supermall', 'count' => count($direct)], ...$counts];
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{value: string, count: int}>
     */
    private function arrivesRows(Collection $rows): array
    {
        $buckets = ['hour' => [], 'tomorrow' => [], 'two_days' => []];

        foreach ($rows as $row) {
            $days = $row->fulfilment === 'fbn' ? 0 : (int) $row->handling_days;

            foreach (['hour' => 0, 'tomorrow' => 1, 'two_days' => 2] as $bucket => $limit) {
                if ($days <= $limit) {
                    $buckets[$bucket][$row->product_id] = true;
                }
            }
        }

        return array_values(array_filter(array_map(fn (string $bucket): array => ['value' => $bucket, 'count' => count($buckets[$bucket])], self::ARRIVES), fn (array $bucket): bool => $bucket['count'] > 0));
    }

    /**
     * The category with this slug and every category below it.
     *
     * @return array<int, int>
     */
    public function categoryAndDescendantIds(string $slug): array
    {
        $parents = Category::query()->pluck('parent_id', 'id');
        $root = Category::query()->where('slug', $slug)->value('id');

        if ($root === null) {
            return [0];
        }

        $ids = [(int) $root];

        for ($frontier = [(int) $root], $depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $frontier = $parents->filter(fn ($parent): bool => in_array((int) $parent, $frontier, true))->keys()->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{slug: string, name: string, count: int}>
     */
    private function brandFacet(array $filters): array
    {
        $counts = $this->query($filters, 'brand')->whereNotNull('products.brand_id')
            ->toBase()->select('products.brand_id', DB::raw('count(*) as total'))->groupBy('products.brand_id')
            ->pluck('total', 'brand_id');

        return Brand::query()->whereIn('id', $counts->keys())->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Brand $brand): array => ['slug' => $brand->slug, 'name' => $brand->name, 'count' => (int) $counts[$brand->id]])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{slug: string, name: string, rating: string|null, count: int}>
     */
    private function sellerFacet(array $filters): array
    {
        $counts = $this->storeOffers($filters)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIntegerInRaw('product_variants.product_id', $this->query($filters, 'seller')->pluck('products.id')->all())
            ->toBase()
            ->select('listings.vendor_id', DB::raw('count(distinct product_variants.product_id) as total'))
            ->groupBy('listings.vendor_id')
            ->pluck('total', 'vendor_id');

        return Vendor::query()->whereIn('id', $counts->keys())->orderBy('store_name')->get()
            ->map(fn (Vendor $vendor): array => ['slug' => $vendor->slug, 'name' => $vendor->store_name, 'rating' => $vendor->rating, 'count' => (int) $counts[$vendor->id]])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{min: array{amount: string, currency: string}|null, max: array{amount: string, currency: string}|null}
     */
    private function priceFacet(array $filters): array
    {
        $prices = $this->query($filters, 'price')->select(['best_price' => $this->bestPriceSubquery()]);
        $range = DB::connection($prices->getModel()->getConnectionName())->query()->fromSub($prices->toBase(), 'priced')
            ->selectRaw('min(best_price) as min_price, max(best_price) as max_price')->first();

        return [
            'min' => MoneyResource::make($range?->min_price === null ? null : (string) $range->min_price),
            'max' => MoneyResource::make($range?->max_price === null ? null : (string) $range->max_price),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{min: int, count: int}>
     */
    private function ratingFacet(array $filters): array
    {
        $ratings = $this->query($filters, 'rating')->whereNotNull('products.rating_average')->toBase()->pluck('products.rating_average');

        return array_map(fn (int $min): array => [
            'min' => $min,
            'count' => $ratings->filter(fn ($average): bool => (float) $average >= $min)->count(),
        ], [4, 3, 2, 1]);
    }

    /**
     * Option values (colour, size, storage, gender…) of the matching
     * products' variants; counts are distinct products.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{key: string, values: array<int, array{value: string, count: int}>}>
     */
    private function attributeFacet(array $filters): array
    {
        $variants = ProductVariant::query()
            ->whereIntegerInRaw('product_id', $this->query($filters, 'attr')->pluck('products.id')->all())
            ->when($filters['store'] ?? null, fn (Builder $query, int $vendorId) => $query->whereIn('id', $this->storeOffers($filters)->select('listings.product_variant_id')))
            ->whereNotNull('option_values')
            ->toBase()
            ->get(['product_id', 'option_values']);
        $products = [];

        foreach ($variants as $variant) {
            $options = is_string($variant->option_values) ? json_decode($variant->option_values, true) : (array) $variant->option_values;

            foreach ((array) $options as $key => $value) {
                if (is_scalar($value) && preg_match(self::ATTRIBUTE_KEY, (string) $key) && (string) $value !== '') {
                    $products[$key][(string) $value][$variant->product_id] = true;
                }
            }
        }

        ksort($products);

        return array_values(array_map(function (string $key, array $values): array {
            $rows = array_map(fn (string|int $value, array $ids): array => ['value' => (string) $value, 'count' => count($ids)], array_keys($values), $values);
            usort($rows, fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

            return ['key' => $key, 'values' => $rows];
        }, array_keys($products), $products));
    }

    /**
     * Fulfilled by: fbm / fbn per offer column, plus `supermall` (direct
     * from the brand) when any matching product has such an offer.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{value: string, count: int}>
     */
    private function fulfilmentFacet(array $filters): array
    {
        $rows = $this->offerFacet($filters, 'fulfilment');
        $direct = $this->storeOffers($filters)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIntegerInRaw('product_variants.product_id', $this->query($filters, 'fulfilment')->pluck('products.id')->all())
            ->whereExists(self::directFromBrand())
            ->toBase()
            ->distinct()
            ->count('product_variants.product_id');

        return $direct > 0 ? [['value' => 'supermall', 'count' => $direct], ...$rows] : $rows;
    }

    /**
     * Arrives by: products with an offer in each (cumulative) bucket.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{value: string, count: int}>
     */
    private function arrivesFacet(array $filters): array
    {
        $row = $this->storeOffers($filters)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIntegerInRaw('product_variants.product_id', $this->query($filters, 'arrives')->pluck('products.id')->all())
            ->toBase()
            ->selectRaw("count(distinct case when listings.fulfilment = 'fbn' or listings.handling_days <= 0 then product_variants.product_id end) as hour")
            ->selectRaw("count(distinct case when listings.fulfilment = 'fbn' or listings.handling_days <= 1 then product_variants.product_id end) as tomorrow")
            ->selectRaw("count(distinct case when listings.fulfilment = 'fbn' or listings.handling_days <= 2 then product_variants.product_id end) as two_days")
            ->first();

        return array_values(array_filter(array_map(fn (string $bucket): array => ['value' => $bucket, 'count' => (int) ($row?->{$bucket} ?? 0)], self::ARRIVES), fn (array $bucket): bool => $bucket['count'] > 0));
    }

    /**
     * Matching products per category (every filter but the category),
     * rolled up the tree: a parent counts its descendants. Only categories
     * with products are listed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{slug: string, count: int}>
     */
    private function categoryFacet(array $filters): array
    {
        $direct = $this->query($filters, 'category')->toBase()
            ->select('products.category_id', DB::raw('count(*) as total'))->groupBy('products.category_id')
            ->pluck('total', 'category_id');

        if ($direct->isEmpty()) {
            return [];
        }

        $categories = Category::query()->where('is_active', true)->get(['id', 'parent_id', 'slug'])->keyBy('id');
        $totals = [];

        foreach ($direct as $categoryId => $count) {
            for ($node = $categories->get($categoryId), $depth = 0; $node !== null && $depth <= 10; $node = $categories->get($node->parent_id), $depth++) {
                $totals[$node->id] = ($totals[$node->id] ?? 0) + (int) $count;
            }
        }

        arsort($totals);

        return array_values(array_map(fn (int $id, int $count): array => ['slug' => $categories[$id]->slug, 'count' => $count], array_keys($totals), $totals));
    }

    /**
     * Products per offer value (fulfilment fbm / fbn, condition or grade),
     * with every other filter applied.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{value: string, count: int}>
     */
    private function offerFacet(array $filters, string $column): array
    {
        return $this->storeOffers($filters)
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIntegerInRaw('product_variants.product_id', $this->query($filters, $column)->pluck('products.id')->all())
            ->whereNotNull("listings.{$column}")
            ->toBase()
            ->select("listings.{$column} as value", DB::raw('count(distinct product_variants.product_id) as total'))
            ->groupBy("listings.{$column}")
            ->orderByDesc('total')->orderBy('value')
            ->get()
            ->map(fn ($row): array => ['value' => (string) $row->value, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * Sellable offers, only the store's when the search is scoped to one.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Listing>
     */
    private function storeOffers(array $filters): Builder
    {
        return $this->sellableListings()
            ->when($filters['store'] ?? null, fn (Builder $listings, int $vendorId) => $listings->where('listings.vendor_id', $vendorId));
    }

    /**
     * "a,b" or ["a","b"] → ["a","b"].
     *
     * @return array<int, string>
     */
    public static function slugs(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item): string => trim((string) $item), $values), fn (string $item): bool => $item !== '' && mb_strlen($item) <= 255));
    }
}
