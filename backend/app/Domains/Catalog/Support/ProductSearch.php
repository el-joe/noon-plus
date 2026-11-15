<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Support\BuyBox;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product listing filters and facets (G-W4). Every filter narrows the
 * set of active products with a sellable offer; each facet is counted
 * with every other filter applied but its own (so picking one brand still
 * shows the other brands' counts).
 *
 * Filters: q, category (slug, all descendants), brand (slugs, comma
 * separated), seller (vendor slugs), min_price / max_price (buy-box price),
 * rating (minimum average), attr[key] (variant option values, comma separated).
 */
class ProductSearch
{
    public const ATTRIBUTE_KEY = '/^[A-Za-z0-9_]{1,40}$/';

    public function __construct(private BuyBox $buyBox) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function query(array $filters, ?string $except = null): Builder
    {
        $use = fn (string $filter): mixed => $filter === $except ? null : ($filters[$filter] ?? null);
        $bestPrice = $this->buyBox->bestPriceSubquery();

        return Product::query()
            ->where('products.status', 'active')
            ->whereExists($this->buyBox->hasSellableOfferSubquery())
            ->when($use('q'), fn (Builder $query, string $term) => $query->where(
                fn (Builder $search) => $search->where('products.title_en', 'like', '%'.$term.'%')->orWhere('products.title_ar', 'like', '%'.$term.'%')
            ))
            ->when($use('category'), fn (Builder $query, string $slug) => $query->whereIn('products.category_id', $this->categoryAndDescendantIds($slug)))
            ->when(self::slugs($use('brand')), fn (Builder $query, array $slugs) => $query->whereIn('products.brand_id', Brand::query()->select('id')->whereIn('slug', $slugs)))
            ->when(self::slugs($use('seller')), fn (Builder $query, array $slugs) => $query->whereExists(
                $this->buyBox->sellableListings()
                    ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->whereIn('listings.vendor_id', Vendor::query()->select('id')->whereIn('slug', $slugs))
                    ->selectRaw('1')
                    ->toBase()
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
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{brands: array<int, array{slug: string, name: string, count: int}>, sellers: array<int, array{slug: string, name: string, rating: string|null, count: int}>, price: array{min: array{amount: string, currency: string}|null, max: array{amount: string, currency: string}|null}, ratings: array<int, array{min: int, count: int}>, attributes: array<int, array{key: string, values: array<int, array{value: string, count: int}>}>}
     */
    public function facets(array $filters): array
    {
        return [
            'brands' => $this->brandFacet($filters),
            'sellers' => $this->sellerFacet($filters),
            'price' => $this->priceFacet($filters),
            'ratings' => $this->ratingFacet($filters),
            'attributes' => $this->attributeFacet($filters),
        ];
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
        $counts = $this->buyBox->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIn('product_variants.product_id', $this->query($filters, 'seller')->select('products.id'))
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
        $prices = $this->query($filters, 'price')->select(['best_price' => $this->buyBox->bestPriceSubquery()]);
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
        $ratings = $this->query($filters, 'rating')->whereNotNull('products.rating_average')->pluck('products.rating_average');

        return array_map(fn (int $min): array => [
            'min' => $min,
            'count' => $ratings->filter(fn ($average): bool => (float) $average >= $min)->count(),
        ], [4, 3, 2, 1]);
    }

    /**
     * Option values (colour, size…) of the matching products' variants;
     * counts are distinct products.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{key: string, values: array<int, array{value: string, count: int}>}>
     */
    private function attributeFacet(array $filters): array
    {
        $variants = ProductVariant::query()
            ->whereIn('product_id', $this->query($filters, 'attr')->select('products.id'))
            ->whereNotNull('option_values')
            ->get(['product_id', 'option_values']);
        $products = [];

        foreach ($variants as $variant) {
            foreach ((array) $variant->option_values as $key => $value) {
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
