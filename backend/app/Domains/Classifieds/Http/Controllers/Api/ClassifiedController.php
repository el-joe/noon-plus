<?php

namespace App\Domains\Classifieds\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Http\ClassifiedPresenter;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Models\ClassifiedPackage;
use App\Domains\Classifieds\Support\ClassifiedReports;
use App\Domains\Classifieds\Support\ClassifiedRequests;
use App\Domains\Classifieds\Support\ClassifiedSearch;
use App\Domains\Classifieds\Support\ClassifiedSpecs;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public classifieds API (storefront + app, MP v2 §14b): categories with
 * attribute schemas, search with attribute filters / map bounds / sort
 * (featured first), GeoJSON map, ad detail, compare, shop page, packages
 * and abuse reports. Only live ads are public (FR-CLS-2).
 */
class ClassifiedController extends Controller
{
    public function __construct(private ClassifiedSearch $search, private ClassifiedSpecs $specs) {}

    public function categories(): JsonResponse
    {
        $all = ClassifiedCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $tree = function (?int $parentId, string $parentVertical, ?string $parentSection) use (&$tree, $all): array {
            return $all->where('parent_id', $parentId)->values()->map(function (ClassifiedCategory $category) use (&$tree, $parentVertical, $parentSection): array {
                $vertical = $category->vertical !== null && $category->vertical !== 'general' ? $category->vertical : $parentVertical;
                $section = $category->section ?? $parentSection;

                return [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name(),
                    'section' => $section,
                    'own_section' => $category->section,
                    'vertical' => $vertical,
                    'own_vertical' => $category->vertical,
                    'request_types' => app(ClassifiedRequests::class)->typesForVertical($vertical),
                    'children' => $tree($category->id, $vertical, $section),
                ];
            })->all();
        };

        return ApiResponse::success($tree(null, 'general', null));
    }

    public function attributes(string $slug): JsonResponse
    {
        $category = ClassifiedCategory::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return ApiResponse::success([
            'category' => $category->slug,
            'vertical' => $category->inherited('vertical'),
            'section' => $category->inherited('section'),
            'requires_contract' => $category->needsContract(),
            'attributes' => $this->specs->describe($category)->all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->search->query($filters)->paginate($filters['per_page'] ?? 24);

        return ApiResponse::success(collect($page->items())->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad))->all(), [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /**
     * FR-CLS-5 map view: live ads with a location inside the bounds as GeoJSON.
     */
    public function map(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $ads = $this->search->query($filters)->whereNotNull('latitude')->limit(500)->get();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $ads->map(fn (ClassifiedAd $ad): array => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $ad->longitude, (float) $ad->latitude]],
                'properties' => ['number' => $ad->number, 'title' => $ad->title, 'price' => MoneyResource::of($ad->price === null ? null : (string) $ad->price, $ad->currency)['amount'] ?? null, 'currency' => $ad->currency, 'featured' => $ad->featured_until?->isFuture() ?? false],
            ])->all(),
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $ad = ClassifiedAd::query()->where('number', $number)->with(['media', 'category', 'vendor', 'marketer', 'property'])->firstOrFail();
        $viewer = $request->user('sanctum');

        if (! $ad->status->isPublic() && $viewer?->id !== $ad->owner_user_id) {
            abort(404);
        }

        if ($ad->status->isPublic() && $viewer?->id !== $ad->owner_user_id) {
            ClassifiedAd::query()->whereKey($ad->id)->increment('views');
        }

        $similar = ClassifiedAd::query()->where('status', ClassifiedAdStatus::Active)->where('classified_category_id', $ad->classified_category_id)
            ->whereKeyNot($ad->id)->with(['media', 'category', 'vendor'])->latest('bumped_at')->limit(8)->get();

        return ApiResponse::success(ClassifiedPresenter::detail($ad) + [
            'similar' => $similar->map(fn (ClassifiedAd $other): array => ClassifiedPresenter::card($other))->all(),
        ]);
    }

    /**
     * Compare up to `classified.max_compare` live ads of one category side by side.
     */
    public function compare(Request $request, VerticalSettings $settings): JsonResponse
    {
        $numbers = $request->validate(['numbers' => ['required', 'array', 'min:2', 'max:'.$settings->int('classified.max_compare')], 'numbers.*' => ['string', 'max:24']])['numbers'];
        $ads = ClassifiedAd::query()->whereIn('number', $numbers)->where('status', ClassifiedAdStatus::Active)->with(['media', 'category', 'vendor'])->get();

        if ($ads->count() < 2 || $ads->pluck('classified_category_id')->unique()->count() > 1) {
            return ApiResponse::error('compare_mismatch', __('Compare two or more live ads of the same category.'), 422);
        }

        $schema = $this->specs->describe($ads->first()->category);

        return ApiResponse::success([
            'attributes' => $schema->map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label'], 'unit' => $field['unit']])->all(),
            'ads' => $ads->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad) + ['specs' => $ad->specs ?? []])->values()->all(),
        ]);
    }

    public function shop(string $slug): JsonResponse
    {
        $shop = Vendor::query()->where('slug', $slug)->where('type', 'classified')->where('status', Vendor::STATUS_ACTIVE)->firstOrFail();
        $ads = ClassifiedAd::query()->where('vendor_id', $shop->id)->where('status', ClassifiedAdStatus::Active)->with(['media', 'category', 'vendor'])->latest('bumped_at')->paginate(24);

        return ApiResponse::success([
            'shop' => ['slug' => $shop->slug, 'name' => $shop->store_name, 'verified' => $shop->verified_at !== null, 'rating' => $shop->rating, 'member_since' => $shop->created_at?->toDateString(), 'live_ads' => $ads->total()],
            'ads' => collect($ads->items())->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad))->all(),
        ], ['page' => $ads->currentPage(), 'last_page' => $ads->lastPage()]);
    }

    public function packages(): JsonResponse
    {
        return ApiResponse::success(ClassifiedPackage::query()->where('is_active', true)->orderBy('price')->get()->map(fn (ClassifiedPackage $package): array => [
            'code' => $package->code,
            'type' => $package->type->value,
            'name' => app()->getLocale() === 'ar' ? $package->name_ar : $package->name_en,
            'duration_days' => $package->duration_days,
            'price' => MoneyResource::make((string) $package->price),
        ])->all());
    }

    public function report(Request $request, string $number, ClassifiedReports $reports): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:24'], 'details' => ['nullable', 'string', 'max:1000']]);
        $ad = ClassifiedAd::query()->where('number', $number)->where('status', ClassifiedAdStatus::Active)->firstOrFail();
        $report = $reports->report($ad, $request->user('sanctum')?->id, $data['reason'], $data['details'] ?? null);

        return ApiResponse::success(['id' => $report->id, 'status' => $report->status->value], status: 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'in:offer,wanted'],
            'vertical' => ['nullable', 'string', 'max:16', 'alpha_dash'],
            'city' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'verified' => ['nullable', 'boolean'],
            'with_video' => ['nullable', 'boolean'],
            'bounds' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'purpose' => ['nullable', 'in:rent,sale,off_plan'],
            'property_type' => ['nullable', 'string', 'max:24'],
            'bedrooms_min' => ['nullable', 'integer', 'min:0'],
            'bathrooms_min' => ['nullable', 'integer', 'min:0'],
            'min_area' => ['nullable', 'numeric', 'min:0'],
            'max_area' => ['nullable', 'numeric', 'min:0'],
            'attr' => ['nullable', 'array'],
            'sort' => ['nullable', 'in:recommended,newest,price_asc,price_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
    }
}
