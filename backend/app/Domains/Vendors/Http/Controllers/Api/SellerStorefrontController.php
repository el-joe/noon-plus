<?php

namespace App\Domains\Vendors\Http\Controllers\Api;

use App\Domains\Catalog\Http\Controllers\CatalogController;
use App\Domains\Catalog\Http\Requests\ProductIndexRequest;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\Classifieds\Models\Follow;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Support\ReturnWindow;
use App\Domains\Reviews\Models\ProductReview;
use App\Domains\Reviews\Support\RatingAggregates;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller storefront (QC-008, FEATURE_MATRIX §3 `/seller/[slug]`): the
 * store's public profile, rating and policies, its products with the
 * catalogue filters and facets, and following the store. Only active
 * product stores are public; legal details (licence, tax id, address
 * lines) never leave the panel.
 */
class SellerStorefrontController extends Controller
{
    public const FOLLOW_TYPE = 'shop';

    public function show(Request $request, string $slug, RatingAggregates $ratings, ProductSearch $search, ReturnWindow $returnWindow, SettingsService $settings, CountryContext $countryContext): JsonResponse
    {
        $vendor = $this->store($slug);
        $summary = $ratings->summary(ProductReview::query()->where('vendor_id', $vendor->id));
        $positive = $summary['count'] === 0 ? null : (int) round(($summary['histogram'][5] + $summary['histogram'][4]) * 100 / $summary['count']);
        $fulfilment = $vendor->listings()->where('status', 'active')->distinct()->pluck('fulfilment')->all();
        $country = $countryContext->current();
        $setting = fn (string $key): string => (string) $settings->get($key, config("nawy.checkout.defaults.{$country->code}.{$key}"), $country);
        $userId = $request->user('sanctum')?->id;
        $followers = Follow::query()->where(['followable_type' => self::FOLLOW_TYPE, 'followable_id' => $vendor->id]);

        return ApiResponse::success([
            'slug' => $vendor->slug,
            'name' => $vendor->store_name,
            'logo' => PublicUrl::of($vendor->logo_path),
            'banner' => PublicUrl::of($vendor->business_details['store']['banner_path'] ?? null),
            'description' => $vendor->business_details['store']['description'] ?? null,
            'city' => $vendor->business_details['address']['city'] ?? null,
            'joined_at' => $vendor->created_at?->toIso8601String(),
            'rating' => $summary + ['positive_percent' => $positive],
            'product_count' => $search->query(['seller' => $vendor->slug])->count(),
            'followers' => (clone $followers)->count(),
            'following' => $userId !== null && (clone $followers)->where('customer_user_id', $userId)->exists(),
            'badges' => $this->badges($vendor, $summary, $fulfilment),
            'policies' => [
                'returns' => ['window_days' => $returnWindow->countryDefault($country), 'note' => __('Most items can be returned within :days days of delivery. Some categories have their own window, shown on the product page.', ['days' => $returnWindow->countryDefault($country)])],
                'shipping' => [
                    'fee' => MoneyResource::make($setting('shipping_fee_per_seller')),
                    'free_over' => MoneyResource::make($setting('free_shipping_threshold')),
                    'fulfilled_by_nawy' => in_array('fbn', $fulfilment, true),
                ],
                'warranty' => ['note' => __('Extended warranty plans are offered on eligible products at checkout.')],
            ],
            'reviews_url' => '/sellers/'.$vendor->slug.'/reviews',
        ]);
    }

    /**
     * The store's products: the same filters, sorts and paging as
     * `GET products`, with the store as the seller filter.
     */
    public function products(ProductIndexRequest $request, string $slug, CatalogController $catalog, ProductSearch $search): JsonResponse
    {
        $this->scopeToStore($request, $this->store($slug));

        return $catalog->products($request, $search);
    }

    /**
     * Facets of the store's products only: the seller facet lists just this
     * store and option values come from its own offers (RT-005).
     */
    public function facets(ProductIndexRequest $request, string $slug, ProductSearch $search): JsonResponse
    {
        $vendor = $this->store($slug);
        $this->scopeToStore($request, $vendor);
        $filters = $request->validated() + ['store' => (int) $vendor->id];

        return ApiResponse::success($search->facets($filters), ['total' => $search->query($filters)->count()]);
    }

    public function follow(Request $request, string $slug): JsonResponse
    {
        $vendor = $this->store($slug);
        $attributes = ['customer_user_id' => $request->user()->id, 'followable_type' => self::FOLLOW_TYPE, 'followable_id' => $vendor->id];

        if ($request->isMethod('delete')) {
            Follow::query()->where($attributes)->delete();
        } else {
            Follow::query()->firstOrCreate($attributes);
        }

        return ApiResponse::success([
            'following' => ! $request->isMethod('delete'),
            'followers' => Follow::query()->where(['followable_type' => self::FOLLOW_TYPE, 'followable_id' => $vendor->id])->count(),
        ]);
    }

    public function followed(Request $request): JsonResponse
    {
        $page = Follow::query()->where('customer_user_id', $request->user()->id)->where('followable_type', self::FOLLOW_TYPE)
            ->whereIn('followable_id', Vendor::query()->select('id')->where('status', Vendor::STATUS_ACTIVE)->where('type', 'product'))
            ->latest('id')->paginate(min(100, max(1, $request->integer('per_page', 24))));
        $vendors = Vendor::query()->whereIn('id', collect($page->items())->pluck('followable_id'))->get()->keyBy('id');

        return ApiResponse::success(collect($page->items())->map(fn (Follow $follow): array => [
            'slug' => $vendors[$follow->followable_id]->slug,
            'name' => $vendors[$follow->followable_id]->store_name,
            'logo' => PublicUrl::of($vendors[$follow->followable_id]->logo_path),
            'rating' => ['average' => $vendors[$follow->followable_id]->rating, 'count' => (int) $vendors[$follow->followable_id]->rating_count],
            'followed_at' => $follow->created_at?->toIso8601String(),
        ])->all(), ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }

    private function store(string $slug): Vendor
    {
        return Vendor::query()->where('slug', $slug)->where('status', Vendor::STATUS_ACTIVE)->where('type', 'product')->firstOrFail();
    }

    private function scopeToStore(ProductIndexRequest $request, Vendor $vendor): void
    {
        $request->merge(['seller' => $vendor->slug]);
        $request->setValidator(validator($request->all(), $request->rules()));
    }

    /**
     * @param  array{average: string|null, count: int, histogram: array<int, int>}  $summary
     * @param  array<int, string>  $fulfilment
     * @return array<int, array{key: string, label: string}>
     */
    private function badges(Vendor $vendor, array $summary, array $fulfilment): array
    {
        $badges = [];

        if ($summary['count'] >= (int) config('nawy.discovery.top_rated_min_reviews') && $summary['average'] !== null
            && bccomp($summary['average'], (string) config('nawy.discovery.top_rated_min_average'), 2) >= 0) {
            $badges[] = ['key' => 'top_rated', 'label' => __('Top rated')];
        }

        if (in_array('fbn', $fulfilment, true)) {
            $badges[] = ['key' => 'fulfilled_by_nawy', 'label' => __('Fulfilled by Nawy')];
        }

        if ($vendor->created_at !== null && $vendor->created_at->greaterThanOrEqualTo(now()->subDays(90))) {
            $badges[] = ['key' => 'new_seller', 'label' => __('New seller')];
        }

        return $badges;
    }
}
