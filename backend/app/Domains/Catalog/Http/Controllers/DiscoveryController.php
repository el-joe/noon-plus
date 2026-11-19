<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Http\Requests\ProductCompareRequest;
use App\Domains\Catalog\Http\Requests\SearchSuggestRequest;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\FrequentlyBoughtTogether;
use App\Domains\Catalog\Support\ProductCards;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\Catalog\Support\SearchQueryLog;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\BuyBox;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\LikeSearch;
use App\Shared\Http\PublicUrl;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Discovery extras (QC-043, G-A1, FR-CAT-6): popular and trending
 * searches, search-box suggestions, "frequently bought together" and
 * product comparison. Public reads in the market's country.
 */
class DiscoveryController extends Controller
{
    public function __construct(private SearchQueryLog $searches, private ProductSearch $search, private BuyBox $buyBox) {}

    public function popular(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'between:1,20']])['limit'] ?? 10);

        return ApiResponse::success($this->searches->popular($limit)->map(fn (array $row): array => ['query' => $row['query']])->all());
    }

    /**
     * Rising searches; with no search data yet, the busiest root categories
     * stand in (`meta.source` says which).
     */
    public function trending(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'between:1,20']])['limit'] ?? 10);
        $queries = $this->searches->trending($limit);

        if ($queries->isNotEmpty()) {
            return ApiResponse::success($queries->map(fn (array $row): array => ['type' => 'query', 'label' => $row['query'], 'query' => $row['query'], 'category' => null])->all(), ['source' => 'searches']);
        }

        $counts = $this->search->query([])->toBase()->select('products.category_id', DB::raw('count(*) as total'))->groupBy('products.category_id')->pluck('total', 'category_id');
        $categories = Category::query()->where('is_active', true)->get(['id', 'parent_id', 'slug', 'name_en', 'name_ar'])->keyBy('id');
        $byRoot = [];

        foreach ($categories as $category) {
            $root = $category;

            for ($depth = 0; $root->parent_id !== null && $categories->has($root->parent_id) && $depth < 10; $depth++) {
                $root = $categories[$root->parent_id];
            }

            $byRoot[$root->id] = ($byRoot[$root->id] ?? 0) + (int) ($counts[$category->id] ?? 0);
        }

        arsort($byRoot);
        $roots = collect(array_keys(array_filter($byRoot)))->take($limit)->map(fn (int $id): Category => $categories[$id]);

        return ApiResponse::success($roots->map(fn (Category $category): array => ['type' => 'category', 'label' => $category->name(), 'query' => null, 'category' => $category->slug])->values()->all(), ['source' => 'categories']);
    }

    /**
     * Search box: matching popular searches, products, brands and
     * categories. The typed text is matched literally (`%` and `_` too).
     */
    public function suggest(SearchSuggestRequest $request): JsonResponse
    {
        $term = trim((string) $request->validated('q'));
        $limit = (int) ($request->validated('limit') ?? 6);
        $like = LikeSearch::contains($term);
        $products = $this->search->query([])
            ->where(fn ($query) => $query->where('products.title_en', 'like', $like)->orWhere('products.title_ar', 'like', $like))
            ->addSelect(['products.*', 'best_price' => $this->buyBox->bestPriceSubquery()])
            ->with('media')
            ->orderByRaw('products.bestseller_rank is null')->orderBy('products.bestseller_rank')->orderByDesc('products.rating_count')->orderBy('products.id')
            ->limit($limit)->get();
        $brands = Brand::query()->where('is_active', true)->where('name', 'like', $like)
            ->whereIn('id', $this->search->query([])->whereNotNull('products.brand_id')->select('products.brand_id'))
            ->orderBy('name')->limit(4)->get();
        $categories = Category::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like))
            ->orderBy('sort_order')->orderBy('id')->limit(4)->get();

        return ApiResponse::success([
            'queries' => $this->searches->popular(5, $term)->pluck('query')->all(),
            'products' => $products->map(fn (Product $product): array => [
                'slug' => $product->slug,
                'title' => $product->title(),
                'image' => $product->primaryImage()?->toPublicArray(),
                'price' => MoneyResource::make($product->best_price),
            ])->all(),
            'brands' => $brands->map(fn (Brand $brand): array => ['slug' => $brand->slug, 'name' => $brand->name, 'logo' => PublicUrl::of($brand->logo_path)])->all(),
            'categories' => $categories->map(fn (Category $category): array => ['slug' => $category->slug, 'name' => $category->name()])->all(),
        ]);
    }

    /**
     * Products bought in the same orders, each with its buy-box listing so
     * the bundle can go to the cart, and the bundle price with this product.
     */
    public function frequentlyBoughtTogether(Request $request, string $slug, FrequentlyBoughtTogether $together, ProductCards $cards, MoneyFactory $money, CountryContext $countryContext): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
        $shared = $together->for($product);
        $buyBoxes = $this->buyBoxListings([$product->id, ...array_keys($shared)]);
        $products = $cards->load(array_keys($shared));
        $items = collect(array_keys($shared))
            ->filter(fn (int $id): bool => $products->has($id) && $buyBoxes->has($id))
            ->take(4)
            ->map(fn (int $id): array => (new ProductCardResource($products[$id]))->resolve($request) + [
                'listing_id' => $buyBoxes[$id]->id,
                'bought_together_orders' => $shared[$id],
            ])->values();
        $currency = $countryContext->current()->currency;
        $total = $items->isEmpty() || ! $buyBoxes->has($product->id) ? null : $items->reduce(
            fn ($sum, array $item) => $sum->plus($money->of((string) $item['price']['amount'], $currency)),
            $money->of((string) $buyBoxes[$product->id]->effectivePrice(), $currency),
        );

        return ApiResponse::success([
            'product' => ['slug' => $product->slug, 'listing_id' => $buyBoxes->get($product->id)?->id],
            'items' => $items->all(),
            'bundle_total' => $total === null ? null : MoneyResource::of((string) $total->getAmount(), $currency),
        ]);
    }

    /**
     * Side by side (FR-CAT-6): price, rating, brand, best seller, variant
     * options and the specification rows of every product (blank where a
     * product has no value for a row).
     */
    public function compare(ProductCompareRequest $request, ProductCards $cards): JsonResponse
    {
        $slugs = $request->slugs();
        $ids = Product::query()->whereIn('slug', $slugs)->where('status', 'active')->pluck('id', 'slug');
        $ordered = collect($slugs)->filter(fn (string $slug): bool => $ids->has($slug))->map(fn (string $slug): int => (int) $ids[$slug])->values()->all();
        $products = $cards->load($ordered);
        $products->load('variants');
        $buyBoxes = $this->buyBoxListings($ordered);
        $locale = app()->getLocale();
        $columns = collect($ordered)->filter(fn (int $id): bool => $products->has($id))->map(function (int $id) use ($products, $buyBoxes, $request, $locale): array {
            $product = $products[$id];
            $options = [];

            foreach ($product->variants as $variant) {
                foreach ((array) $variant->option_values as $key => $value) {
                    if (is_scalar($value) && (string) $value !== '') {
                        $options[$key][(string) $value] = true;
                    }
                }
            }

            return (new ProductCardResource($product))->resolve($request) + [
                'model_number' => $product->model_number,
                'in_stock' => $buyBoxes->has($id),
                'listing_id' => $buyBoxes->get($id)?->id,
                'seller' => $buyBoxes->get($id)?->vendor?->store_name,
                'options' => array_map(fn (array $values): array => array_keys($values), $options),
                'specifications' => $product->content($locale)['specifications'],
            ];
        })->values();

        $labels = $columns->flatMap(fn (array $column): array => array_column($column['specifications'], 'label'))->unique()->values();
        $rows = $labels->map(fn (string $label): array => [
            'label' => $label,
            'values' => $columns->map(fn (array $column): ?string => collect($column['specifications'])->firstWhere('label', $label)['value'] ?? null)->all(),
        ])->all();

        return ApiResponse::success([
            'products' => $columns->map(fn (array $column): array => array_diff_key($column, ['specifications' => true]))->all(),
            'specifications' => $rows,
        ]);
    }

    /**
     * The buy-box listing (cheapest sellable offer, then the better-rated
     * seller) of each product, in one query.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Listing>
     */
    private function buyBoxListings(array $productIds): Collection
    {
        return $this->buyBox->sellableListings()
            ->select('listings.*', 'product_variants.product_id as buy_box_product_id')
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('vendors', 'vendors.id', '=', 'listings.vendor_id')
            ->whereIn('product_variants.product_id', $productIds)
            ->with('vendor')
            ->orderByRaw('coalesce(listings.sale_price, listings.price) asc')
            ->orderByDesc('vendors.rating')
            ->orderBy('listings.id')
            ->get()
            ->unique('buy_box_product_id')
            ->keyBy(fn (Listing $listing): int => (int) $listing->buy_box_product_id);
    }
}
