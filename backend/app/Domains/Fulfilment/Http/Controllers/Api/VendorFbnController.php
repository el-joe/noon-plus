<?php

namespace App\Domains\Fulfilment\Http\Controllers\Api;

use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Models\InboundLine;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\FbnStockReport;
use App\Domains\Fulfilment\Support\InboundLabels;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vendor app (`/ops/vendor/fbn/*`): FBN stock per SKU and warehouse, the
 * warehouses, FBN enrolment per listing (enrol / leave), inbound plans
 * (list, show, create, submit, ship, cancel) and their carton-label PDF —
 * the same services as Seller Center → Inventory & warehouses.
 * State refusals answer 422 `transition_refused`.
 */
class VendorFbnController extends Controller
{
    use RefusesTransitions;

    public function __construct(private InboundShipments $inbound) {}

    public function stock(Request $request, FbnStockReport $report): JsonResponse
    {
        $low = $request->boolean('low');
        $rows = $report->forVendor($this->vendor($request)->id)
            ->when($low, fn ($rows) => $rows->where('low_stock', true))
            ->map(fn (array $row): array => [
                'listing_id' => $row['listing']->id,
                'sku' => $row['sku'],
                'title' => $row['title'],
                'on_hand' => $row['on_hand'],
                'reserved' => $row['reserved'],
                'available' => $row['available'],
                'damaged' => $row['damaged'],
                'low_stock' => $row['low_stock'],
                'long_term' => $row['long_term'],
                'unsellable' => $row['unsellable'],
                'warehouses' => $row['warehouses'],
            ])->values()->all();

        return ApiResponse::success($rows);
    }

    public function warehouses(): JsonResponse
    {
        return ApiResponse::success(Warehouse::query()->active()->orderBy('code')->get()->map(fn (Warehouse $warehouse): array => [
            'id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name(), 'city' => $warehouse->city, 'address' => $warehouse->address,
        ])->all());
    }

    /**
     * FBN enrolment per listing (as Seller Center → Inventory & warehouses):
     * the store's listings (not archived) with how each is fulfilled, its
     * warehouse units and whether it can join or leave FBN now.
     */
    public function listings(Request $request): JsonResponse
    {
        $vendorId = $this->vendor($request)->id;
        $filter = $request->validate(['fulfilment' => ['nullable', Rule::in(['fbn', 'fbm'])]])['fulfilment'] ?? null;
        $page = Listing::query()->where('vendor_id', $vendorId)->where('status', '!=', ListingStatus::Archived)->with('variant.product')
            ->when($filter === 'fbn', fn ($query) => $query->where('fulfilment', 'fbn'))
            ->when($filter === 'fbm', fn ($query) => $query->where('fulfilment', '!=', 'fbn'))
            ->orderBy('id')->paginate(50);
        $units = WarehouseStock::query()->whereIn('listing_id', $page->getCollection()->pluck('id'))
            ->selectRaw('listing_id, SUM(on_hand) as on_hand, SUM(damaged) as damaged')->groupBy('listing_id')->get()->keyBy('listing_id');

        return ApiResponse::success($page->getCollection()->map(fn (Listing $listing): array => $this->listingRow($listing, $units->get($listing->id)))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'has_warehouses' => Warehouse::query()->active()->exists(),
        ]);
    }

    /**
     * Enrol a listing in FBN (its own declared units go off sale until the
     * warehouse receives them) or leave FBN (warehouse empty, no open
     * inbound plan or removal). 422 `transition_refused` otherwise.
     */
    public function enrolment(Request $request, int $listing, string $action, FbnEnrolment $enrolment): JsonResponse
    {
        $listing = Listing::query()->where('vendor_id', $this->vendor($request)->id)->with('variant.product')->findOrFail($listing);

        return $this->refusable(function () use ($listing, $action, $enrolment, $request): JsonResponse {
            $listing = $action === 'enrol' ? $enrolment->enrol($listing, $request->user()->id) : $enrolment->leave($listing, $request->user()->id);
            $units = WarehouseStock::query()->where('listing_id', $listing->id)->selectRaw('SUM(on_hand) as on_hand, SUM(damaged) as damaged')->first();

            return ApiResponse::success($this->listingRow($listing->loadMissing('variant.product'), $units));
        });
    }

    /**
     * The plan's carton labels as a PDF (A6, one label per carton) — the
     * file Seller Center prints, for the app to save or share.
     */
    public function labels(Request $request, int $shipment, InboundLabels $labels): Response
    {
        $shipment = $this->own($request, $shipment);

        if (! $shipment->status->hasLabels()) {
            return ApiResponse::error('transition_refused', __('Submit the plan to print its labels.'), 409);
        }

        return $labels->pdf($shipment);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(InboundStatus::class)]])['status'] ?? null;
        $page = InboundShipment::query()->where('vendor_id', $this->vendor($request)->id)->with('warehouse')->withCount('lines')->withSum('lines', 'quantity_expected')
            ->when($status, fn ($query, string $value) => $query->where('status', $value))->latest('id')->paginate(30);

        return ApiResponse::success($page->getCollection()->map(fn (InboundShipment $shipment): array => $this->row($shipment))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        return $this->present($this->own($request, $shipment));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:today'],
            'cartons' => ['nullable', 'integer', 'between:1,999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'submit' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.listing_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'between:1,100000'],
            'lines.*.cartons' => ['nullable', 'integer', 'between:1,999'],
        ]);

        return $this->refusable(function () use ($request, $data): JsonResponse {
            $shipment = $this->inbound->draft($this->vendor($request), (int) $data['warehouse_id'], $data['lines'], $data, $request->user()->id);

            if ($request->boolean('submit')) {
                $this->inbound->submit($shipment, $request->user()->id);
            }

            return $this->present($shipment, 201);
        });
    }

    public function act(Request $request, int $shipment, string $action): JsonResponse
    {
        $shipment = $this->own($request, $shipment);
        $userId = $request->user()->id;
        $data = $action === 'ship' ? $request->validate(['carrier_name' => ['required', 'string', 'max:80'], 'tracking_number' => ['nullable', 'string', 'max:80']]) : [];

        return $this->refusable(fn (): JsonResponse => $this->present(match ($action) {
            'submit' => $this->inbound->submit($shipment, $userId),
            'ship' => $this->inbound->markShipped($shipment, $data['carrier_name'], $data['tracking_number'] ?? null, $userId),
            'cancel' => $this->inbound->cancel($shipment, ActorType::Vendor, $userId, $request->input('reason')),
        }));
    }

    private function present(InboundShipment $shipment, int $status = 200): JsonResponse
    {
        $shipment->refresh()->load(['warehouse', 'lines.listing.variant.product'])->loadCount('lines')->loadSum('lines', 'quantity_expected');

        return ApiResponse::success($this->row($shipment) + [
            'carrier_name' => $shipment->carrier_name,
            'tracking_number' => $shipment->tracking_number,
            'notes' => $shipment->notes,
            'labels_url' => $shipment->status->hasLabels() ? route('partner.inventory.inbound.labels-pdf', $shipment->id) : null,
            // In-app download (ApiClient.download): the same PDF through the vendor API.
            'labels_path' => $shipment->status->hasLabels() ? "ops/vendor/fbn/inbound/{$shipment->id}/labels.pdf" : null,
            'lines' => $shipment->lines->map(fn (InboundLine $line): array => [
                'id' => $line->id,
                'listing_id' => $line->listing_id,
                'sku' => $line->listing->variant->sku,
                'title' => $line->listing->variant->product->title(),
                'quantity_expected' => $line->quantity_expected,
                'cartons' => $line->cartons,
                'quantity_received' => $line->quantity_received,
                'quantity_damaged' => $line->quantity_damaged,
                'quantity_short' => in_array($shipment->status, [InboundStatus::Received, InboundStatus::Closed], true) ? $line->shortQuantity() : null,
            ])->all(),
        ], status: $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InboundShipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'reference' => $shipment->reference(),
            'status' => $shipment->status->value,
            'warehouse' => ['id' => $shipment->warehouse->id, 'code' => $shipment->warehouse->code, 'name' => $shipment->warehouse->name()],
            'lines_count' => (int) $shipment->lines_count,
            'units' => (int) $shipment->lines_sum_quantity_expected,
            'cartons' => (int) $shipment->cartons,
            'expected_at' => $shipment->expected_at?->toDateString(),
            'submitted_at' => $shipment->submitted_at?->toIso8601String(),
            'can_submit' => $shipment->status === InboundStatus::Draft,
            'can_ship' => $shipment->status === InboundStatus::Submitted,
            'can_cancel' => in_array(InboundStatus::Cancelled, $shipment->status->allowedNext(), true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listingRow(Listing $listing, mixed $units): array
    {
        $isFbn = $listing->fulfilment === 'fbn';
        $warehouseUnits = (int) ($units->on_hand ?? 0) + (int) ($units->damaged ?? 0);

        return [
            'listing_id' => $listing->id,
            'sku' => $listing->variant?->sku,
            'title' => $listing->variant?->product?->title(),
            'status' => $listing->status->value,
            'fulfilment' => $isFbn ? 'fbn' : 'fbm',
            'warehouse_units' => $warehouseUnits,
            'can_enrol' => ! $isFbn,
            // Leaving needs the warehouse empty; open inbound plans / removals are checked on the request.
            'can_leave' => $isFbn && $warehouseUnits === 0,
        ];
    }

    private function vendor(Request $request): Vendor
    {
        return $request->attributes->get('ops_workspace');
    }

    private function own(Request $request, int $id): InboundShipment
    {
        return InboundShipment::query()->where('vendor_id', $this->vendor($request)->id)->findOrFail($id);
    }
}
