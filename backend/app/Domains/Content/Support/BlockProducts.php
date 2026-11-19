<?php

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Http\Resources\ProductCardResource;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\CardOffers;
use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\BuyBox;
use App\Shared\Http\PublicCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product cards for page-builder product blocks (`product_carousel`,
 * `product_grid`), one card per product (its buy-box offer; only products
 * with a sellable offer). Settings:
 *
 * - `source`: `manual` (`listing_ids`, in that order), `new_arrivals`,
 *   `category` (`category` slug, sub-categories included), `brand`
 *   (`brand` slug), `bestsellers` (weekly rank), `top_rated`, `deals`
 *   (sale price or a running flash deal), `trending` (30-day units, not
 *   the ranked bestsellers), `recommended` (top rated and bestselling,
 *   spread across categories, deterministic) or `coupon` (`coupon` code:
 *   what the coupon targets);
 * - `category` / `brand` also narrow every other source;
 * - `max_items` (1–48, default 12);
 * - `fallback_source`: used to fill up when the source finds fewer than
 *   `min_items` (default 4) products.
 *
 * Answers are cached per country, locale and settings for a few minutes.
 */
class BlockProducts
{
    public const SOURCES = ['manual', 'new_arrivals', 'category', 'brand', 'bestsellers', 'top_rated', 'deals', 'trending', 'recommended', 'coupon'];

    public const CACHE_GROUP = 'block-products';

    private const CACHE_SECONDS = 300;

    /**
     * @var array<int, array{parent_id: int|null, slug: string}>|null
     */
    private ?array $categories = null;

    public function __construct(private BuyBox $buyBox) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function cards(array $settings): array
    {
        $source = is_string($settings['source'] ?? null) ? $settings['source'] : null;

        if (! in_array($source, self::SOURCES, true)) {
            return [];
        }

        $relevant = array_intersect_key($settings, array_flip(['source', 'listing_ids', 'category', 'brand', 'coupon', 'max_items', 'fallback_source', 'min_items']));
        ksort($relevant);

        return PublicCache::remember(PublicCache::countryGroup(self::CACHE_GROUP), app()->getLocale().':'.md5((string) json_encode($relevant)), self::CACHE_SECONDS, fn (): array => $this->build($source, $settings));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function build(string $source, array $settings): array
    {
        $limit = min(48, max(1, (int) ($settings['max_items'] ?? 12)));

        if ($source === 'manual') {
            return $this->manual(array_map('intval', (array) ($settings['listing_ids'] ?? [])), $limit);
        }

        $ids = $this->productIds($source, $settings, $limit);
        $fallback = is_string($settings['fallback_source'] ?? null) ? $settings['fallback_source'] : null;

        if (count($ids) < min($limit, max(1, (int) ($settings['min_items'] ?? 4))) && $fallback !== null && $fallback !== $source && in_array($fallback, self::SOURCES, true) && $fallback !== 'manual') {
            $ids = array_values(array_unique([...$ids, ...$this->productIds($fallback, $settings, $limit)]));
            $ids = array_slice($ids, 0, $limit);
        }

        return $this->productCards($ids);
    }

    /**
     * @param  array<int, int>  $listingIds
     * @return array<int, array<string, mixed>>
     */
    private function manual(array $listingIds, int $limit): array
    {
        if ($listingIds === []) {
            return [];
        }

        return Listing::query()->where('status', ListingStatus::Active)->with(ListingCards::relations())->whereKey($listingIds)->limit($limit)->get()
            ->sortBy(fn (Listing $listing): int|false => array_search($listing->id, $listingIds, true))->values()
            ->map(fn (Listing $listing): array => ListingCards::card($listing))->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, int>
     */
    public function productIds(string $source, array $settings, int $limit): array
    {
        $query = Product::query()->where('products.status', 'active')->whereExists($this->buyBox->hasSellableOfferSubquery());

        $category = is_string($settings['category'] ?? null) && $settings['category'] !== '' ? $settings['category'] : null;
        $brand = is_string($settings['brand'] ?? null) && $settings['brand'] !== '' ? $settings['brand'] : null;

        if ($category !== null) {
            $query->whereIn('products.category_id', $this->categoryTree($this->categoryIds([$category])));
        } elseif ($source === 'category') {
            return [];
        }

        if ($brand !== null) {
            $query->whereIn('products.brand_id', Brand::query()->select('id')->where('slug', $brand));
        } elseif ($source === 'brand') {
            return [];
        }

        switch ($source) {
            case 'new_arrivals':
                $query->orderByDesc('products.created_at')->orderByDesc('products.id');
                break;
            case 'top_rated':
                $query->whereNotNull('products.rating_average')->where('products.rating_count', '>', 0)
                    ->orderByDesc('products.rating_average')->orderByDesc('products.rating_count')->orderByDesc('products.id');
                break;
            case 'deals':
                $query->where(fn (Builder $deal) => $deal
                    ->whereExists($this->offersOfProduct()->whereNotNull('listings.sale_price')->whereColumn('listings.sale_price', '<', 'listings.price')->selectRaw('1')->toBase())
                    ->orWhereExists($this->offersOfProduct()->whereIn('listings.id', $this->liveFlashListings())->selectRaw('1')->toBase()));
                $this->bestsellingFirst($query);
                break;
            case 'trending':
                $query->where('products.units_sold_30d', '>', 0)
                    ->where(fn (Builder $ranked) => $ranked->whereNull('products.bestseller_rank')->orWhere('products.bestseller_rank', '>', (int) config('nawy.discovery.bestseller_badge_top')))
                    ->orderByDesc('products.units_sold_30d')->orderByDesc('products.rating_count')->orderByDesc('products.id');
                break;
            case 'bestsellers':
                $query->where(fn (Builder $sold) => $sold->whereNotNull('products.bestseller_rank')->orWhere('products.units_sold_30d', '>', 0));
                $this->bestsellingFirst($query);
                break;
            case 'recommended':
                return $this->recommended($query, $limit);
            case 'coupon':
                if (! $this->couponTarget($query, (string) ($settings['coupon'] ?? ''))) {
                    return [];
                }
                $this->bestsellingFirst($query);
                break;
            default:
                $this->bestsellingFirst($query);
        }

        return $query->limit($limit)->pluck('products.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Top rated and bestselling products, round-robin over categories so one
     * category does not fill the row.
     *
     * @param  Builder<Product>  $query
     * @return array<int, int>
     */
    private function recommended(Builder $query, int $limit): array
    {
        $candidates = $query
            ->orderByRaw('(coalesce(products.rating_average, 0) * ln(products.rating_count + 2) + case when products.bestseller_rank is null then 0 else 5 end + ln(products.units_sold_30d + 1)) desc')
            ->orderByDesc('products.id')
            ->limit($limit * 4)
            ->get(['products.id', 'products.category_id'])
            ->groupBy('category_id')
            ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all())
            ->values()
            ->all();

        $ids = [];

        for ($round = 0; count($ids) < $limit && $candidates !== []; $round++) {
            $added = false;

            foreach ($candidates as $group) {
                if (isset($group[$round]) && count($ids) < $limit) {
                    $ids[] = $group[$round];
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Narrow to what a live coupon targets; false when there is no such coupon.
     *
     * @param  Builder<Product>  $query
     */
    private function couponTarget(Builder $query, string $code): bool
    {
        $coupon = $code === '' ? null : Coupon::query()->where('code', strtoupper($code))->where('is_active', true)->whereNull('admin_disabled_at')
            ->where(fn ($live) => $live->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($live) => $live->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();

        if ($coupon === null) {
            return false;
        }

        $ids = $coupon->targetIds();

        match ($coupon->target_type ?? CouponTarget::All) {
            CouponTarget::All => null,
            CouponTarget::Category => $query->whereIn('products.category_id', $this->categoryTree($ids)),
            CouponTarget::Product => $query->whereIn('products.id', $ids),
            CouponTarget::Brand => $query->whereIn('products.brand_id', $ids),
            CouponTarget::Vendor => $query->whereExists($this->offersOfProduct()->whereIn('listings.vendor_id', $ids)->selectRaw('1')->toBase()),
        };

        if ($coupon->vendor_id !== null) {
            $query->whereExists($this->offersOfProduct()->where('listings.vendor_id', $coupon->vendor_id)->selectRaw('1')->toBase());
        }

        return true;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function bestsellingFirst(Builder $query): void
    {
        $query->orderByRaw('products.bestseller_rank is null')->orderBy('products.bestseller_rank')
            ->orderByDesc('products.units_sold_30d')->orderByDesc('products.rating_count')->orderByDesc('products.id');
    }

    /**
     * Sellable offers of the outer `products.id`.
     *
     * @return Builder<Listing>
     */
    private function offersOfProduct(): Builder
    {
        return $this->buyBox->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id');
    }

    private function liveFlashListings(): \Illuminate\Database\Query\Builder
    {
        return DB::connection((new Listing)->getConnectionName())->table('flash_sale_submissions')
            ->join('flash_sale_events', 'flash_sale_events.id', '=', 'flash_sale_submissions.flash_sale_event_id')
            ->whereIn('flash_sale_submissions.status', array_map(fn ($status) => $status->value, FlashSaleSubmissionStatus::pricing()))
            ->whereIn('flash_sale_events.status', array_map(fn ($status) => $status->value, FlashSaleEventStatus::pricing()))
            ->where('flash_sale_events.starts_at', '<=', now())
            ->where('flash_sale_events.ends_at', '>', now())
            ->whereColumn('flash_sale_submissions.sold_quantity', '<', 'flash_sale_submissions.quantity_cap')
            ->select('flash_sale_submissions.listing_id');
    }

    /**
     * Cards in the given product order, each with its buy-box `listing_id`.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function productCards(array $ids): array
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

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    public function categoryIds(array $slugs): array
    {
        $ids = [];

        foreach ($this->categoryRows() as $id => $row) {
            if (in_array($row['slug'], $slugs, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The categories and all their descendants.
     *
     * @param  array<int, int>  $rootIds
     * @return array<int, int>
     */
    public function categoryTree(array $rootIds): array
    {
        $children = [];

        foreach ($this->categoryRows() as $id => $row) {
            if ($row['parent_id'] !== null) {
                $children[$row['parent_id']][] = $id;
            }
        }

        $all = [];
        $frontier = $rootIds;

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                if (! isset($all[$id])) {
                    $all[$id] = true;
                    $next = [...$next, ...($children[$id] ?? [])];
                }
            }

            $frontier = $next;
        }

        return array_keys($all) ?: [0];
    }

    /**
     * @return array<int, array{parent_id: int|null, slug: string}>
     */
    private function categoryRows(): array
    {
        return $this->categories ??= Category::query()->where('is_active', true)->get(['id', 'parent_id', 'slug'])
            ->mapWithKeys(fn (Category $category): array => [(int) $category->id => ['parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null, 'slug' => (string) $category->slug]])
            ->all();
    }
}
