<?php

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\CardOffers;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Shared\Http\PublicCache;
use App\Shared\Http\PublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Server-side data of the category page blocks (design
 * category-page-target.webp), always from real catalogue data:
 *
 * - `category_promo_tiles`: `data.captions[slug]` = lowest price and the
 *   deepest sale discount of each tile's `category`;
 * - `category_brand_tiles`: brands selling in `category` (or the listed
 *   `brands`) with a product image, product count, lowest price and
 *   deepest discount;
 * - `category_gift_band`: product cards in `category` priced at most
 *   `max_price`, with their category names as stickers;
 * - `category_deal_columns`: one deal column per child of `category`
 *   (its deepest-discount product on sale or in a running flash deal) and
 *   the end of the flash sale `flash_sale_slug` for the countdown.
 *
 * Cached per country, locale and settings for a few minutes.
 */
class CategoryBlocks
{
    public const TYPES = ['category_promo_tiles', 'category_brand_tiles', 'category_gift_band', 'category_deal_columns'];

    private const CACHE_SECONDS = 300;

    public function __construct(private ProductSearch $search) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    public function data(string $type, array $settings): ?array
    {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $relevant = array_intersect_key($settings, array_flip(['category', 'brands', 'max_items', 'max_price', 'flash_sale_slug', 'tiles', 'hero', 'max_columns']));
        ksort($relevant);

        return PublicCache::remember(PublicCache::countryGroup(BlockProducts::CACHE_GROUP), app()->getLocale().':'.$type.':'.md5((string) json_encode($relevant)), self::CACHE_SECONDS, fn (): ?array => match ($type) {
            'category_promo_tiles' => $this->promoCaptions($settings),
            'category_brand_tiles' => $this->brandTiles($settings),
            'category_gift_band' => $this->giftBand($settings),
            'category_deal_columns' => $this->dealColumns($settings),
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{captions: array<string, array{min_price: array{amount: string, currency: string}|null, max_discount: int}>}|null
     */
    private function promoCaptions(array $settings): ?array
    {
        $slugs = collect([(array) ($settings['hero'] ?? []), ...array_filter((array) ($settings['tiles'] ?? []), 'is_array')])
            ->pluck('category')->filter(fn ($slug): bool => is_string($slug) && $slug !== '')->unique()->values();
        $captions = [];

        foreach ($slugs as $slug) {
            $stats = $this->offerStats($this->search->query(['category' => $slug]));

            if ($stats !== null) {
                $captions[$slug] = $stats;
            }
        }

        return $captions === [] ? null : ['captions' => $captions];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{tiles: array<int, array<string, mixed>>}|null
     */
    private function brandTiles(array $settings): ?array
    {
        $category = self::slug($settings['category'] ?? null);
        $listed = ProductSearch::slugs($settings['brands'] ?? []);
        $limit = min(12, max(1, (int) ($settings['max_items'] ?? 6)));
        $filters = array_filter(['category' => $category]);

        $counts = $this->search->query($filters)->whereNotNull('products.brand_id')
            ->when($listed !== [], fn (Builder $query) => $query->whereIn('products.brand_id', Brand::query()->select('id')->whereIn('slug', $listed)))
            ->toBase()->select('products.brand_id', DB::raw('count(*) as total'))->groupBy('products.brand_id')
            ->orderByDesc('total')->limit(40)->pluck('total', 'brand_id');
        $brands = Brand::query()->whereIn('id', $counts->keys())->where('is_active', true)->get()->keyBy('id');
        $order = $listed !== [] ? $brands->sortBy(fn (Brand $brand): int|false => array_search($brand->slug, $listed, true))->keys() : $counts->keys()->filter(fn ($id): bool => $brands->has($id));
        $tiles = [];

        foreach ($order->take($limit) as $brandId) {
            $brand = $brands[$brandId];
            $products = $this->search->query($filters)->where('products.brand_id', $brandId);
            $top = (clone $products)->orderByRaw('products.bestseller_rank is null')->orderBy('products.bestseller_rank')
                ->orderByDesc('products.rating_count')->orderByDesc('products.id')->with('media')->first();
            $stats = $this->offerStats($products);
            $tiles[] = [
                'slug' => $brand->slug,
                'name' => $brand->name,
                'logo' => PublicUrl::of($brand->logo_path),
                'image' => $top?->primaryImage()?->toPublicArray(),
                'product_count' => (int) $counts[$brandId],
                'min_price' => $stats['min_price'] ?? null,
                'max_discount' => $stats['max_discount'] ?? 0,
            ];
        }

        return $tiles === [] ? null : ['tiles' => $tiles];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{products: array<int, array<string, mixed>>, categories: array<string, string>}|null
     */
    private function giftBand(array $settings): ?array
    {
        $limit = min(12, max(1, (int) ($settings['max_items'] ?? 4)));
        $maxPrice = is_numeric($settings['max_price'] ?? null) ? (string) $settings['max_price'] : null;
        $filters = array_filter(['category' => self::slug($settings['category'] ?? null), 'max_price' => $maxPrice]);

        // One product per sub-category first (varied stickers), best rated first.
        $ids = $this->search->query($filters)
            ->orderByDesc('products.rating_count')->orderByDesc('products.id')
            ->limit($limit * 10)->get(['products.id', 'products.category_id'])
            ->groupBy('category_id')
            ->flatMap(fn ($rows) => $rows->values()->map(fn (Product $product, int $rank): array => ['id' => (int) $product->id, 'rank' => $rank]))
            ->sortBy('rank')->pluck('id')->take($limit)->values()->all();
        $cards = $this->cards($ids);

        if ($cards === []) {
            return null;
        }

        $categories = Category::query()->whereIn('slug', array_filter(array_column($cards, 'category')))->get()
            ->mapWithKeys(fn (Category $category): array => [$category->slug => $category->name()])->all();

        return ['products' => $cards, 'categories' => $categories];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{ends_at: string|null, sale_slug: string|null, columns: array<int, array<string, mixed>>}|null
     */
    private function dealColumns(array $settings): ?array
    {
        $root = Category::query()->where('slug', self::slug($settings['category'] ?? null) ?? '')->where('is_active', true)->first();
        $children = $root === null
            ? Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            : Category::query()->where('parent_id', $root->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $limit = min(6, max(1, (int) ($settings['max_columns'] ?? 5)));
        $saleSlug = self::slug($settings['flash_sale_slug'] ?? null);
        $sale = FlashSaleEvent::query()->whereIn('status', FlashSaleEventStatus::pricing())->where('ends_at', '>', now())
            ->when($saleSlug !== null, fn ($query) => $query->where('slug', $saleSlug), fn ($query) => $query->where('starts_at', '<=', now()))
            ->orderBy('ends_at')->first();
        $columns = [];

        foreach ($children as $child) {
            if (count($columns) >= $limit) {
                break;
            }

            $id = $this->search->query(['category' => $child->slug, 'deals' => true])
                ->orderByDesc('products.rating_count')->orderByDesc('products.id')->value('products.id');
            $cards = $id === null ? [] : $this->cards([(int) $id]);

            if ($cards !== []) {
                $columns[] = ['slug' => $child->slug, 'name' => $child->name(), 'product' => $cards[0]];
            }
        }

        return $columns === [] ? null : ['ends_at' => $sale?->ends_at?->toIso8601String(), 'sale_slug' => $sale?->slug, 'columns' => $columns];
    }

    /**
     * Lowest price and deepest sale / flash discount (whole %) of the products' sellable offers.
     *
     * @param  Builder<Product>  $products
     * @return array{min_price: array{amount: string, currency: string}|null, max_discount: int}|null
     */
    private function offerStats(Builder $products): ?array
    {
        $row = $this->search->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIn('product_variants.product_id', $products->select('products.id'))
            ->toBase()
            ->selectRaw('min(coalesce(listings.sale_price, listings.price)) as min_price')
            ->selectRaw('max(case when listings.sale_price is not null and listings.sale_price < listings.price and listings.price > 0 then (listings.price - listings.sale_price) / listings.price else 0 end) as max_discount')
            ->first();

        if ($row?->min_price === null) {
            return null;
        }

        return ['min_price' => MoneyResource::make((string) $row->min_price), 'max_discount' => (int) floor((float) $row->max_discount * 100)];
    }

    /**
     * Product cards in the given order (buy-box offer facts attached).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $ids)->with(['brand', 'category', 'media'])->get()->keyBy('id');
        CardOffers::attach($products);
        $request = request();

        return collect($ids)->filter(fn (int $id): bool => $products->has($id) && $products[$id]->best_listing_id !== null)
            ->map(fn (int $id): array => (new ProductCardResource($products[$id]))->resolve($request))
            ->values()->all();
    }

    private static function slug(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9-]{1,120}$/', $value) ? $value : null;
    }
}
