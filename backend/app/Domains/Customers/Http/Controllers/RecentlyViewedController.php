<?php

namespace App\Domains\Customers\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\ProductCards;
use App\Domains\Customers\Models\RecentlyViewedProduct;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recently viewed, synced across the customer's devices (QC-043): the
 * device posts what it viewed (also its local history after sign-in);
 * the newest view of a product wins and only the latest
 * `nawy.discovery.recently_viewed_max` products are kept.
 */
class RecentlyViewedController extends Controller
{
    public function __construct(private ProductCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->list($request));
    }

    /**
     * `items: [{slug, viewed_at?}]` (newest first, up to 50). Unknown or
     * inactive products are skipped; a view in the future counts as now.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.slug' => ['required', 'string', 'max:255'],
            'items.*.viewed_at' => ['nullable', 'date'],
        ]);
        $userId = $request->user()->id;
        $now = CarbonImmutable::now();
        $ids = Product::query()->whereIn('slug', array_column($data['items'], 'slug'))->where('status', 'active')->pluck('id', 'slug');
        $views = [];

        foreach ($data['items'] as $position => $item) {
            if (! $ids->has($item['slug'])) {
                continue;
            }

            $at = isset($item['viewed_at']) ? CarbonImmutable::parse($item['viewed_at'])->utc() : $now->subSeconds($position);
            $productId = (int) $ids[$item['slug']];

            if (! isset($views[$productId]) || $at->greaterThan($views[$productId])) {
                $views[$productId] = $at->greaterThan($now) ? $now : $at;
            }
        }

        DB::connection((new RecentlyViewedProduct)->getConnectionName())->transaction(function () use ($views, $userId): void {
            foreach ($views as $productId => $at) {
                $row = RecentlyViewedProduct::query()->firstOrNew(['customer_user_id' => $userId, 'product_id' => $productId]);

                if ($row->viewed_at === null || $at->greaterThan($row->viewed_at)) {
                    $row->viewed_at = $at;
                    $row->save();
                }
            }

            $keep = RecentlyViewedProduct::query()->where('customer_user_id', $userId)
                ->orderByDesc('viewed_at')->orderByDesc('id')->limit((int) config('nawy.discovery.recently_viewed_max'))->pluck('id');
            RecentlyViewedProduct::query()->where('customer_user_id', $userId)->whereNotIn('id', $keep)->delete();
        });

        return ApiResponse::success($this->list($request));
    }

    public function destroy(Request $request, ?string $slug = null): JsonResponse
    {
        RecentlyViewedProduct::query()->where('customer_user_id', $request->user()->id)
            ->when($slug !== null, fn ($query) => $query->whereIn('product_id', Product::query()->select('id')->where('slug', $slug)))
            ->delete();

        return ApiResponse::success($this->list($request));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(Request $request): array
    {
        $views = RecentlyViewedProduct::query()->where('customer_user_id', $request->user()->id)
            ->orderByDesc('viewed_at')->orderByDesc('id')->limit((int) config('nawy.discovery.recently_viewed_max'))
            ->pluck('viewed_at', 'product_id');
        $cards = collect($this->cards->cards(array_map('intval', $views->keys()->all()), $request));
        $idBySlug = Product::query()->whereIn('id', $views->keys())->pluck('id', 'slug');

        return $cards->map(fn (array $card): array => $card + [
            'viewed_at' => $views[$idBySlug[$card['slug']]]?->toIso8601String(),
        ])->all();
    }
}
