<?php

namespace App\Domains\Vendors\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\InsufficientStock;
use App\Domains\Listings\Http\Requests\AdjustStockRequest;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SellerFulfilment;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\LikeSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vendor ops app (Sanctum `ops:vendor`): the Seller Center store's
 * dashboard, sub-orders with the fulfilment steps of {@see SellerFulfilment}
 * and stock moves through the {@see InventoryLedger}, like the panel
 * (SellerOrderController, ListingController@adjustStock). Another store's
 * order or listing is a 404.
 */
class VendorOpsController extends Controller
{
    use RefusesTransitions;

    /**
     * Seller Center's "low stock" filter: 1 to 5 units available.
     */
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(private SellerFulfilment $fulfilment, private CountryContext $countryContext) {}

    /**
     * KPIs from the store's sub-orders (days in country time):
     * - to_confirm: `placed` (waiting for "Confirm order");
     * - to_pack: `confirmed` + `processing` (accepted, not packed yet);
     * - ready_to_ship: `packed` (waiting for "Ready for pickup");
     * - orders_today / sales_today: sub-orders created today (sales exclude `cancelled`);
     * - sales_7d: today and the 6 days before, without `cancelled`;
     * - low_stock: listings (not archived) with 1 to {@see LOW_STOCK_THRESHOLD} units.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $vendor = $this->vendor($request);
        $country = $this->countryContext->current();
        $today = CarbonImmutable::now($country->timezone)->startOfDay();
        $orders = fn (): Builder => SubOrder::query()->where('vendor_id', $vendor->id);
        $counts = $orders()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $count = fn (SubOrderStatus ...$statuses): int => (int) collect($statuses)->sum(fn (SubOrderStatus $status): int => (int) ($counts[$status->value] ?? 0));

        return ApiResponse::success([
            'store' => ['id' => $vendor->id, 'name' => $vendor->store_name],
            'kpis' => [
                'to_confirm' => $count(SubOrderStatus::Placed),
                'to_pack' => $count(SubOrderStatus::Confirmed, SubOrderStatus::Processing),
                'ready_to_ship' => $count(SubOrderStatus::Packed),
                'orders_today' => $orders()->where('created_at', '>=', $today->utc())->count(),
                'sales_today' => MoneyResource::make((string) $orders()->where('status', '!=', SubOrderStatus::Cancelled)->where('created_at', '>=', $today->utc())->sum('total')),
                'sales_7d' => MoneyResource::make((string) $orders()->where('status', '!=', SubOrderStatus::Cancelled)->where('created_at', '>=', $today->subDays(6)->utc())->sum('total')),
                'low_stock' => Listing::query()->where('vendor_id', $vendor->id)->where('status', '!=', ListingStatus::Archived)
                    ->withAvailableQuantity()->havingRaw('coalesce(available_quantity, 0) between 1 and ?', [self::LOW_STOCK_THRESHOLD])->get()->count(),
            ],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(SubOrderStatus::class)], 'q' => ['nullable', 'string', 'max:60']]);
        $page = SubOrder::query()->where('vendor_id', $this->vendor($request)->id)
            ->with('order:id,placed_at,currency')->withCount('items')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('number', 'like', LikeSearch::contains($search)))
            ->latest('id')->paginate(20);

        return ApiResponse::success($page->getCollection()->map(fn (SubOrder $subOrder): array => $this->orderRow($subOrder))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function order(Request $request, int $subOrder): JsonResponse
    {
        return $this->presentOrder($this->ownSubOrder($request, $subOrder));
    }

    public function advance(Request $request, int $subOrder): JsonResponse
    {
        $subOrder = $this->ownSubOrder($request, $subOrder);
        $to = SubOrderStatus::from($request->validate(['to' => ['required', Rule::enum(SubOrderStatus::class)]])['to']);

        return $this->refusable(fn (): JsonResponse => $this->presentOrder($this->fulfilment->advance($subOrder, $to, $request->user()->id)));
    }

    public function cancel(Request $request, int $subOrder): JsonResponse
    {
        $subOrder = $this->ownSubOrder($request, $subOrder);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        return $this->refusable(fn (): JsonResponse => $this->presentOrder($this->fulfilment->cancel($subOrder, $reason, $request->user()->id)));
    }

    public function stock(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'low' => ['nullable', 'boolean']]);
        $page = Listing::query()->where('vendor_id', $this->vendor($request)->id)
            ->where('status', '!=', ListingStatus::Archived)
            ->with('variant.product')->withAvailableQuantity()
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($scoped) => $scoped
                ->where('vendor_sku', 'like', LikeSearch::contains($search))
                ->orWhereHas('variant', fn ($variant) => $variant->where('sku', 'like', LikeSearch::contains($search))
                    ->orWhereHas('product', fn ($product) => $product->where('title_en', 'like', LikeSearch::contains($search))->orWhere('title_ar', 'like', LikeSearch::contains($search))))))
            ->when($filters['low'] ?? false, fn ($query) => $query->havingRaw('coalesce(available_quantity, 0) between 1 and ?', [self::LOW_STOCK_THRESHOLD]))
            ->latest('id')->paginate(30);

        return ApiResponse::success($page->getCollection()->map(fn (Listing $listing): array => $this->stockRow($listing))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    /**
     * Same input as Seller Center → Products → stock: `quantity` (non-zero
     * integer, positive adds, negative removes) and `reason`
     * (`nawy.vendors.stock_reasons`).
     */
    public function adjustStock(AdjustStockRequest $request, int $listing, InventoryLedger $ledger): JsonResponse
    {
        $listing = Listing::query()->where('vendor_id', $this->vendor($request)->id)->findOrFail($listing);

        if ($listing->status === ListingStatus::Archived) {
            return ApiResponse::error('transition_refused', __('An archived listing cannot be changed.'), 422);
        }

        if ($listing->fulfilment === 'fbn') {
            return ApiResponse::error('fbn_stock', __('Fulfilled by Nawy stock is counted by the warehouse. Send units with an inbound plan or request a removal.'), 422);
        }

        try {
            $ledger->move($listing, (int) $request->validated('quantity'), $request->validated('reason'), actorUserId: $request->user()->id);
        } catch (InsufficientStock $insufficient) {
            $message = __('Only :count units are in stock.', ['count' => $insufficient->available]);

            return ApiResponse::error('insufficient_stock', $message, 422, ['quantity' => [$message]]);
        }

        return ApiResponse::success($this->stockRow(Listing::query()->with('variant.product')->withAvailableQuantity()->findOrFail($listing->id)));
    }

    private function vendor(Request $request): Vendor
    {
        return $request->attributes->get('ops_workspace');
    }

    private function ownSubOrder(Request $request, int $id): SubOrder
    {
        return SubOrder::query()->where('vendor_id', $this->vendor($request)->id)->findOrFail($id);
    }

    private function presentOrder(SubOrder $subOrder): JsonResponse
    {
        $subOrder = $subOrder->refresh()->load(['order:id,placed_at,currency', 'items'])->loadCount('items');

        return ApiResponse::success($this->orderRow($subOrder) + [
            'items' => $subOrder->items->map(fn (OrderItem $item): array => [
                'title' => $item->title,
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
                'unit_price' => MoneyResource::of((string) $item->unit_price, $subOrder->order?->currency),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(SubOrder $subOrder): array
    {
        $next = $this->fulfilment->nextStepFor($subOrder);

        return [
            'id' => $subOrder->id,
            'number' => $subOrder->number,
            'status' => $subOrder->status->value,
            'placed_at' => ($subOrder->order?->placed_at ?? $subOrder->created_at)?->toIso8601String(),
            'items_count' => (int) $subOrder->items_count,
            'total' => MoneyResource::of((string) $subOrder->total, $subOrder->order?->currency),
            'next_step' => $next?->value,
            'next_step_label' => $next === null ? null : $this->fulfilment->stepLabel($next),
            'can_cancel' => $this->fulfilment->canCancel($subOrder),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stockRow(Listing $listing): array
    {
        return [
            'listing_id' => $listing->id,
            'title' => $listing->variant?->product?->title_en,
            'sku' => $listing->vendor_sku ?: $listing->variant?->sku,
            'stock' => (int) ($listing->available_quantity ?? 0),
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'status' => $listing->status->value,
            'fulfilment' => (string) $listing->fulfilment,
        ];
    }
}
