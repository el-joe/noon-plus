<?php

namespace App\Domains\FlashSales\Http\Controllers;

use App\Domains\Catalog\Support\ListingCards;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Http\Resources\FlashSaleEventResource;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Public flash sales for the Deals page: running and upcoming (approved)
 * events of the market's country with their approved/live deals.
 */
class FlashSaleController extends Controller
{
    public function index(): JsonResponse
    {
        $events = $this->published()->orderBy('starts_at')->orderBy('id')->get();

        return ApiResponse::success(FlashSaleEventResource::collection($events));
    }

    /**
     * By id or by slug (G-W30).
     */
    public function show(string $id): JsonResponse
    {
        $event = ctype_digit($id) ? $this->published()->findOrFail((int) $id) : $this->published()->where('slug', $id)->firstOrFail();

        return ApiResponse::success(new FlashSaleEventResource($event));
    }

    /**
     * @return Builder<FlashSaleEvent>
     */
    private function published(): Builder
    {
        return FlashSaleEvent::query()
            ->whereIn('status', FlashSaleEventStatus::pricing())
            ->where('ends_at', '>', now())
            ->with(['submissions' => fn ($query) => $query
                ->whereIn('status', [...FlashSaleSubmissionStatus::pricing(), FlashSaleSubmissionStatus::SoldOut])
                ->orderBy('flash_price')
                ->orderBy('id')
                ->with(ListingCards::relations('listing.'))]);
    }
}
