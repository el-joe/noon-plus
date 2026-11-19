<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Advertising\Support\SponsoredRanker;
use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\Catalog\Http\Requests\ProductIndexRequest;
use App\Domains\Catalog\Http\Resources\CategoryResource;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\OfferResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Catalog\Support\CategoryMenu;
use App\Domains\Catalog\Support\Measurements;
use App\Domains\Catalog\Support\ProductBadges;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\Catalog\Support\SearchQueryLog;
use App\Domains\Catalog\Support\SizeGuides;
use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\Page;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\FlashSales\Support\FlashSalePricing;
use App\Domains\Listings\Support\BuyBox;
use App\Domains\Shipping\Support\ListingDeliveryPromise;
use App\Domains\Warranty\Support\WarrantyPlanCatalog;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\LikeSearch;
use App\Shared\Http\PublicCache;
use App\Shared\Http\PublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public catalogue API for the storefront and the customer app.
 */
class CatalogController extends Controller
{
    public function __construct(private BuyBox $buyBox) {}

    public function categories(): JsonResponse
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(CategoryResource::collection($roots));
    }

    /**
     * The whole active category tree with product counts (a parent counts
     * its descendants' products too).
     */
    public function categoryTree(): JsonResponse
    {
        // WP11: public and not personalised — cached briefly per country and locale, forgotten when a category changes.
        return ApiResponse::success(Cache::remember(
            self::treeCacheKey(app(CountryContext::class)->current()->code, app()->getLocale()),
            now()->addMinutes(5),
            fn (): array => $this->buildCategoryTree(),
        ));
    }

    public static function treeCacheKey(string $countryCode, string $locale): string
    {
        return "catalog:{$countryCode}:category-tree:{$locale}";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryTree(): array
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $counts = $this->productCountsByCategory();
        $children = $categories->groupBy(fn (Category $category): int => (int) $category->parent_id);
        $build = function (int $parentId, int $depth) use (&$build, $children, $counts): array {
            return $depth > 10 ? [] : $children->get($parentId, collect())->map(function (Category $category) use (&$build, $depth, $counts): array {
                $nodes = $build($category->id, $depth + 1);

                return [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name(),
                    'image' => PublicUrl::of($category->image_path),
                    'product_count' => (int) ($counts[$category->id] ?? 0) + array_sum(array_column($nodes, 'product_count')),
                    'children' => $nodes,
                ];
            })->values()->all();
        };

        return $build(0, 0);
    }

    /**
     * Header mega menu: top-level categories → level-2 columns → level-3
     * links, the category image and its top brands ({@see CategoryMenu}).
     */
    public function menu(CategoryMenu $menu): JsonResponse
    {
        return ApiResponse::success($menu->cached(app()->getLocale()));
    }

    /**
     * One category by slug: breadcrumbs (root first), children and counts.
     */
    public function category(string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)
            ->with(['children' => fn ($query) => $query->where('is_active', true), 'parent'])
            ->firstOrFail();
        // WP11: one grouped count + the parent map instead of a full search per child (N+1).
        $counts = $this->productCountsByCategory();
        $parents = Category::query()->pluck('parent_id', 'id');
        $count = function (Category $node) use ($counts, $parents): int {
            $total = 0;

            for ($frontier = [(int) $node->id], $depth = 0; $frontier !== [] && $depth <= 10; $depth++) {
                $total += array_sum(array_map(fn (int $id): int => (int) ($counts[$id] ?? 0), $frontier));
                $frontier = $parents->filter(fn ($parent): bool => in_array((int) $parent, $frontier, true))->keys()->map(fn ($id): int => (int) $id)->all();
            }

            return $total;
        };

        // Category page tiles: "From AED 499" / "Up to 40%" per child, rolled up like the counts.
        $offers = $this->offerStatsByCategory();
        $stats = function (Category $node) use ($offers, $parents): array {
            $min = null;
            $discount = 0;

            for ($frontier = [(int) $node->id], $depth = 0; $frontier !== [] && $depth <= 10; $depth++) {
                foreach ($frontier as $id) {
                    if (isset($offers[$id])) {
                        $min = $min === null || (float) $offers[$id]['min_price'] < (float) $min ? $offers[$id]['min_price'] : $min;
                        $discount = max($discount, $offers[$id]['max_discount']);
                    }
                }
                $frontier = $parents->filter(fn ($parent): bool => in_array((int) $parent, $frontier, true))->keys()->map(fn ($id): int => (int) $id)->all();
            }

            return ['min_price' => MoneyResource::make($min), 'max_discount' => $discount];
        };

        return ApiResponse::success([
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name(),
            'image' => PublicUrl::of($category->image_path),
            'product_count' => $count($category),
            ...$stats($category),
            'breadcrumbs' => array_map(fn (Category $node): array => ['slug' => $node->slug, 'name' => $node->name()], array_reverse($category->lineage())),
            'children' => $category->children->map(fn (Category $child): array => [
                'id' => $child->id,
                'slug' => $child->slug,
                'name' => $child->name(),
                'image' => PublicUrl::of($child->image_path),
                'product_count' => $count($child),
                ...$stats($child),
            ])->values(),
        ]);
    }

    /**
     * Active brands with sellable products (optionally within a category).
     */
    public function brands(Request $request, ProductSearch $search): JsonResponse
    {
        $filters = $request->validate(['category' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:100']]);
        $build = function () use ($filters, $search): array {
            $counts = $search->query(['category' => $filters['category'] ?? null])->whereNotNull('products.brand_id')
                ->toBase()->select('products.brand_id', DB::raw('count(*) as total'))->groupBy('products.brand_id')->pluck('total', 'brand_id');

            return Brand::query()->where('is_active', true)->whereIn('id', $counts->keys())
                ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where('name', 'like', LikeSearch::contains($term)))
                ->orderBy('name')
                ->get()
                ->map(fn (Brand $brand): array => [
                    'slug' => $brand->slug,
                    'name' => $brand->name,
                    'logo' => PublicUrl::of($brand->logo_path),
                    'product_count' => (int) $counts[$brand->id],
                ])->values()->all();
        };

        // The unfiltered list (per category) is cached briefly per country; a brand change forgets it. Searches are not cached.
        $brands = ($filters['q'] ?? null) === null
            ? PublicCache::remember(PublicCache::countryGroup(PublicCache::BRANDS), 'list:'.sha1((string) ($filters['category'] ?? '')), 300, $build)
            : $build();

        return ApiResponse::success($brands);
    }

    /**
     * Brand landing data (G-W16) for `/{market}/brands/{slug}`: the brand,
     * its live product count, the categories it sells in, top-rated
     * products and the page-builder page to render instead when one is
     * published with the brand's slug (or `brand-{slug}`).
     */
    public function brand(Request $request, string $slug, ProductSearch $search): JsonResponse
    {
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $filters = ['brand' => $brand->slug];
        $byCategory = $search->query($filters)->toBase()->select('products.category_id', DB::raw('count(*) as total'))->groupBy('products.category_id')->pluck('total', 'category_id');
        $categories = Category::query()->whereIn('id', $byCategory->keys())->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => ['id' => $category->id, 'slug' => $category->slug, 'name' => $category->name(), 'count' => (int) $byCategory[$category->id]])
            ->sortByDesc('count')->values();
        $top = $search->query($filters)->addSelect(['products.*', 'best_price' => $this->buyBox->bestPriceSubquery()])->with(['brand', 'category', 'media'])
            ->orderByRaw('products.rating_average is null')->orderByDesc('products.rating_average')->orderByDesc('products.rating_count')->orderByDesc('products.id')
            ->limit(12)->get();
        $page = Page::query()->whereIn('slug', [$brand->slug, 'brand-'.$brand->slug])->where('locale', app()->getLocale())->where('status', PageStatus::Published)->orderByRaw('slug = ? desc', [$brand->slug])->value('slug');

        return ApiResponse::success([
            'slug' => $brand->slug,
            'name' => $brand->name,
            'logo' => PublicUrl::of($brand->logo_path),
            'product_count' => (int) $byCategory->sum(),
            'categories' => $categories->all(),
            'top_products' => ProductCardResource::collection($top)->resolve($request),
            'page' => $page,
            'products_url' => '/products?brand='.$brand->slug,
        ]);
    }

    /**
     * Facet counts for the same filters as `GET products` (G-W4).
     */
    public function facets(ProductIndexRequest $request, ProductSearch $search): JsonResponse
    {
        $filters = $request->validated();

        return ApiResponse::success($search->facets($filters), ['total' => $search->query($filters)->count()]);
    }

    public function products(ProductIndexRequest $request, ProductSearch $search): JsonResponse
    {
        $filters = $request->validated();

        $query = $search->query($filters)
            ->addSelect(['products.*', 'best_price' => $search->bestPriceSubquery()])
            ->with(['brand', 'category', 'media']);

        match ($filters['sort'] ?? 'recommended') {
            'price_asc' => $query->orderBy('best_price')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('best_price')->orderBy('products.id'),
            // QC-015: weekly bestseller ranking; unranked products last.
            'bestselling' => $query->orderByRaw('products.bestseller_rank is null')->orderBy('products.bestseller_rank')->orderByDesc('products.id'),
            'rating' => $query->orderByRaw('products.rating_average is null')->orderByDesc('products.rating_average')->orderByDesc('products.rating_count')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };

        $page = $query->paginate($filters['per_page'] ?? 24);

        // QC-043: count the search (first page only) for popular / trending searches.
        if (filled($filters['q'] ?? null) && $page->currentPage() === 1) {
            app(SearchQueryLog::class)->record($filters['q'], $page->total());
        }
        $items = app(SponsoredRanker::class)->interleave(collect($page->items()), [
            'q' => $filters['q'] ?? null,
            'category' => $filters['category'] ?? null,
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ], $request->user('sanctum')?->id !== null ? 'u'.$request->user('sanctum')->id : $request->header('X-Visitor-Token'));
        (new EloquentCollection(array_map(fn (array $item): Product => $item['product'], $items)))->loadMissing('media');
        $cards = array_map(fn (array $item): array => (new ProductCardResource($item['product']))->resolve($request) + [
            'sponsored' => $item['sponsored'],
            'label' => $item['label'],
            'ad_token' => $item['ad_token'],
        ], $items);

        return ApiResponse::success($cards, [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function product(string $slug, FlashSalePricing $flashPricing, ListingDeliveryPromise $deliveryPromise, WarrantyPlanCatalog $warrantyPlans): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->with(['brand', 'category', 'variants', 'media.variant'])->firstOrFail();
        $offers = $this->buyBox->offersFor($product);
        $deals = $flashPricing->activeDeals($offers->modelKeys());
        $held = CartInventoryLock::query()->whereIn('listing_id', $offers->modelKeys())->where('expires_at', '>', now())
            ->groupBy('listing_id')->selectRaw('listing_id, SUM(quantity) as held')->pluck('held', 'listing_id');
        $offerResources = $offers->map(function ($offer) use ($product, $deals, $deliveryPromise, $held): OfferResource {
            $offer->variant->setRelation('product', $product);
            $sellable = max(0, (int) $offer->available_quantity - (int) ($held[$offer->id] ?? 0));

            return new OfferResource($offer, $deals->get($offer->id), $deliveryPromise->estimate($offer), $sellable);
        });
        $content = $product->content(app()->getLocale());
        $plans = $warrantyPlans->forCategories([$product->category_id])->get($product->category_id, collect());

        return ApiResponse::success([
            'slug' => $product->slug,
            'title' => $product->title(),
            'brand' => $product->brand?->name,
            'brand_slug' => $product->brand?->slug,
            'model_number' => $product->model_number,
            'badges' => ProductBadges::forProductPage($product, $offers->first(), $offers->first() === null ? null : $deals->get($offers->first()->id)),
            'category' => new CategoryResource($product->category),
            'breadcrumbs' => array_map(fn (Category $node): array => ['slug' => $node->slug, 'name' => $node->name()], array_reverse($product->category->lineage())),
            'description' => $content['description'],
            'highlights' => $content['highlights'],
            'specifications' => $content['specifications'],
            'content' => ['en' => $product->content('en'), 'ar' => $product->content('ar')],
            'images' => $product->media->map(fn (ProductMedia $media): array => $media->toPublicArray())->values(),
            'rating' => ['average' => $product->rating_average, 'count' => (int) $product->rating_count],
            'fulfilment_mode' => $product->fulfilment_mode,
            'lead_days' => $product->lead_days,
            'made_to_order' => $product->fulfilment_mode === 'made_to_order',
            'measurement_fields' => app(Measurements::class)->describe($product),
            'has_size_guide' => app(SizeGuides::class)->resolve($product, $offers->first()?->vendor_id) !== null,
            'buy_box' => $offerResources->first(),
            'other_offers' => $offerResources->slice(1)->values(),
            'from_price' => MoneyResource::make($offers->first()?->effectivePrice()),
            'warranty_plans' => $plans->map(fn ($plan): array => [
                'plan_id' => $plan->id,
                'name' => $plan->name(),
                'months' => $plan->duration_months,
                'price' => MoneyResource::make((string) $plan->price),
            ])->values(),
        ]);
    }

    /**
     * Lowest offer price and the deepest sale discount (whole %) of the
     * sellable offers on active products, per category id.
     *
     * @return array<int, array{min_price: string, max_discount: int}>
     */
    private function offerStatsByCategory(): array
    {
        return app(ProductSearch::class)->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.status', 'active')
            ->toBase()
            ->select('products.category_id')
            ->selectRaw('min(coalesce(listings.sale_price, listings.price)) as min_price')
            ->selectRaw('max(case when listings.sale_price is not null and listings.sale_price < listings.price and listings.price > 0 then (listings.price - listings.sale_price) / listings.price else 0 end) as max_discount')
            ->groupBy('products.category_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->category_id => ['min_price' => (string) $row->min_price, 'max_discount' => (int) floor((float) $row->max_discount * 100)]])
            ->all();
    }

    /**
     * Active products with a sellable offer, counted per category id.
     *
     * @return Collection<int|string, mixed>
     */
    private function productCountsByCategory(): Collection
    {
        return app(ProductSearch::class)->query([])
            ->toBase()->select('products.category_id', DB::raw('count(*) as total'))->groupBy('products.category_id')
            ->pluck('total', 'category_id');
    }

    /**
     * Size-guide drawer (MP v2 §14b): the resolved template in cm or
     * inches; for abayas `height` (+ `height_unit` cm | ft) returns the
     * recommended length and sleeve.
     */
    public function sizeGuide(Request $request, string $slug, SizeGuides $guides): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->with('category')->firstOrFail();
        $template = $guides->resolve($product, $this->buyBox->offersFor($product)->first()?->vendor_id);

        if ($template === null) {
            return ApiResponse::error('size_guide_missing', __('This product has no size guide.'), 404);
        }

        $data = $request->validate(['unit' => ['nullable', 'in:cm,in'], 'height' => ['nullable', 'numeric', 'min:1', 'max:250'], 'height_unit' => ['nullable', 'in:cm,ft']]);
        $guide = $guides->render($template, $data['unit'] ?? null);

        if (isset($data['height'])) {
            $guide['recommendation'] = $guides->abayaFor($template, (float) $data['height'], $data['height_unit'] ?? 'cm');
        }

        return ApiResponse::success($guide);
    }
}
