<?php

namespace App\Domains\Coupons\Http\Controllers\Api;

use App\Domains\Catalog\Models\Product;
use App\Domains\Coupons\Support\PublicOffers;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\Listings\Support\BuyBox;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Offers for you" (QC-064 home) and the PDP coupon chips (QC-057,
 * QC-064): public coupons the shopper can use, plus the running and
 * upcoming flash sales. The customer token is optional.
 */
class OfferController extends Controller
{
    public function __construct(private PublicOffers $offers) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user('sanctum')?->id;
        $events = FlashSaleEvent::query()
            ->whereIn('status', FlashSaleEventStatus::pricing())
            ->where('ends_at', '>', now())
            ->withCount(['submissions' => fn ($query) => $query->whereIn('status', FlashSaleSubmissionStatus::pricing())])
            ->orderBy('starts_at')->orderBy('id')->limit(10)->get();

        return ApiResponse::success([
            'coupons' => $this->offers->present($this->offers->forShopper($userId), $userId),
            'flash_sales' => $events->map(fn (FlashSaleEvent $event): array => [
                'id' => $event->id,
                'slug' => $event->slug,
                'name' => $event->display_name ?? $event->name,
                'state' => $event->starts_at->lessThanOrEqualTo(now()) ? 'live' : 'upcoming',
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'deals_count' => (int) $event->submissions_count,
            ])->all(),
        ]);
    }

    public function forProduct(Request $request, string $slug, BuyBox $buyBox): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
        $offers = $buyBox->offersFor($product)->each(fn ($listing) => $listing->variant->setRelation('product', $product));
        $userId = $request->user('sanctum')?->id;

        return ApiResponse::success($this->offers->present($this->offers->forOffers($offers, $userId), $userId));
    }
}
