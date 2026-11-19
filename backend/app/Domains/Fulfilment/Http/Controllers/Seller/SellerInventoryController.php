<?php

namespace App\Domains\Fulfilment\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\RemovalType;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\RemovalOrder;
use App\Domains\Fulfilment\Models\StorageInvoice;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\FbnFees;
use App\Domains\Fulfilment\Support\FbnSettings;
use App\Domains\Fulfilment\Support\FbnStockReport;
use App\Domains\Fulfilment\Support\InboundLabels;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Fulfilment\Support\RemovalOrders;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller Center → Inventory & warehouses (Fulfilled by Nawy): FBN stock per
 * SKU and warehouse with alerts, FBN enrolment per listing, inbound plans
 * (create, submit, ship, labels), removal requests and FBN fees (preview
 * and history).
 */
class SellerInventoryController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private CountryContext $countryContext,
        private InboundShipments $inbound,
        private RemovalOrders $removals,
        private FbnEnrolment $enrolment,
        private FbnStockReport $report,
        private FbnSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        $vendor = $this->workspace->vendor();
        $rows = $this->report->forVendor($vendor->id);
        $filter = $request->validate(['alert' => ['nullable', Rule::in(['low', 'long_term', 'unsellable'])]])['alert'] ?? null;

        return view('panels.partner.inventory.index', [
            'rows' => $filter === null ? $rows : $rows->filter(fn (array $row): bool => $row[$filter === 'low' ? 'low_stock' : $filter]),
            'totals' => [
                'available' => $rows->sum('available'), 'reserved' => $rows->sum('reserved'), 'damaged' => $rows->sum('damaged'),
                'low' => $rows->where('low_stock', true)->count(),
                'open_inbound' => InboundShipment::query()->where('vendor_id', $vendor->id)->whereIn('status', [InboundStatus::Draft, InboundStatus::Submitted, InboundStatus::InTransit, InboundStatus::Arrived, InboundStatus::Receiving])->count(),
            ],
            'alerts' => ['low' => $rows->where('low_stock', true)->count(), 'long_term' => $rows->where('long_term', true)->count(), 'unsellable' => $rows->where('unsellable', true)->count()],
            'filter' => $filter,
            'candidates' => Listing::query()->where('vendor_id', $vendor->id)->where('fulfilment', '!=', 'fbn')->where('status', '!=', ListingStatus::Archived)->with('variant.product')->orderBy('id')->get(),
            'threshold' => $this->settings->int('low_stock_threshold'),
            'hasWarehouses' => Warehouse::query()->active()->exists(),
        ]);
    }

    public function enrol(Request $request): RedirectResponse
    {
        $listing = $this->ownListing((int) $request->validate(['listing_id' => ['required', 'integer']])['listing_id']);
        $this->enrolment->enrol($listing, $request->user()->id);

        return back()->with('status', __(':sku is now fulfilled by Nawy. Send its units with an inbound plan.', ['sku' => $listing->variant->sku]));
    }

    public function leave(Request $request, int $listing): RedirectResponse
    {
        $listing = $this->ownListing($listing);
        $this->enrolment->leave($listing, $request->user()->id);

        return back()->with('status', __(':sku is fulfilled by you again.', ['sku' => $listing->variant->sku]));
    }

    public function inboundIndex(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(InboundStatus::class)]])['status'] ?? null;

        return view('panels.partner.inventory.inbound', [
            'shipments' => InboundShipment::query()->where('vendor_id', $this->workspace->vendor()->id)->with('warehouse')->withCount('lines')->withSum('lines', 'quantity_expected')
                ->when($status, fn ($query, string $value) => $query->where('status', $value))->latest('id')->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function createInbound(): View
    {
        return view('panels.partner.inventory.inbound-create', [
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'listings' => $this->fbnListings(),
        ]);
    }

    public function storeInbound(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:today'],
            'cartons' => ['nullable', 'integer', 'between:1,999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'max:50'],
            'lines.*.listing_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'integer', 'between:1,100000'],
            'lines.*.cartons' => ['nullable', 'integer', 'between:1,999'],
            'intent' => ['nullable', Rule::in(['draft', 'submit'])],
        ]);
        $lines = array_values(array_filter($data['lines'], fn (array $line): bool => ! empty($line['listing_id']) && ! empty($line['quantity'])));
        $shipment = $this->inbound->draft($this->workspace->vendor(), (int) $data['warehouse_id'], $lines, $data, $request->user()->id);

        if (($data['intent'] ?? 'draft') === 'submit') {
            $this->inbound->submit($shipment, $request->user()->id);
        }

        return redirect()->route('partner.inventory.inbound.show', $shipment->id)->with('status', $shipment->status === InboundStatus::Draft
            ? __('Inbound plan saved as a draft.') : __('Inbound plan :number submitted. Print the labels and ship it.', ['number' => $shipment->number]));
    }

    public function showInbound(int $shipment): View
    {
        $shipment = $this->ownShipment($shipment);
        $shipment->load(['lines.listing.variant.product', 'warehouse', 'transitions']);

        return view('panels.partner.inventory.inbound-show', ['shipment' => $shipment]);
    }

    public function actInbound(Request $request, int $shipment, string $action): RedirectResponse
    {
        $shipment = $this->ownShipment($shipment);
        $userId = $request->user()->id;

        match ($action) {
            'submit' => $this->inbound->submit($shipment, $userId),
            'ship' => $this->ship($request, $shipment, $userId),
            'cancel' => $this->inbound->cancel($shipment, ActorType::Vendor, $userId, $request->input('reason')),
        };

        return back()->with('status', __('Inbound plan updated.'));
    }

    public function labels(Request $request, int $shipment, InboundLabels $labels): Response|View
    {
        $shipment = $this->ownShipment($shipment);
        abort_unless($shipment->status->hasLabels(), 409, __('Submit the plan to print its labels.'));

        return $request->routeIs('partner.inventory.inbound.labels-pdf') ? $labels->pdf($shipment) : $labels->view($shipment);
    }

    public function removalsIndex(FbnFees $fees): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.inventory.removals', [
            'removals' => RemovalOrder::query()->where('vendor_id', $vendor->id)->with(['warehouse', 'lines.listing.variant'])->latest('id')->paginate(20),
            'rows' => $this->report->forVendor($vendor->id)->filter(fn (array $row): bool => $row['on_hand'] + $row['damaged'] > 0),
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'fees' => $this->settings->fees(),
            'currency' => $this->countryContext->current()->currency,
        ]);
    }

    public function storeRemoval(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(RemovalType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'address.name' => ['nullable', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:30'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:50'],
            'lines.*.listing_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'lines.*.source' => ['nullable', Rule::in(['sellable', 'damaged'])],
        ]);
        $lines = array_values(array_filter($data['lines'], fn (array $line): bool => ! empty($line['listing_id']) && ! empty($line['quantity'])));
        $order = $this->removals->request($this->workspace->vendor(), (int) $data['warehouse_id'], RemovalType::from($data['type']), $lines, $data['address'] ?? null, $data['reason'] ?? null, $request->user()->id);

        return back()->with('status', __('Removal :number requested.', ['number' => $order->number]));
    }

    public function cancelRemoval(Request $request, int $removal): RedirectResponse
    {
        $order = RemovalOrder::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($removal);
        $this->removals->cancel($order, ActorType::Vendor, $request->user()->id);

        return back()->with('status', __('Removal :number cancelled.', ['number' => $order->number]));
    }

    public function fees(Request $request, FbnFees $fees, MoneyFactory $money): View
    {
        $vendor = $this->workspace->vendor();
        $currency = $this->countryContext->current()->currency;
        $listingId = $request->validate(['listing_id' => ['nullable', 'integer']])['listing_id'] ?? null;
        $preview = null;

        if ($listingId !== null) {
            $listing = $this->ownListing((int) $listingId);
            $outbound = $fees->outboundPerUnit($listing, $listing->effectivePrice(), $currency);
            $cubicFeet = $fees->unitCubicFeet($listing->variant);
            $preview = [
                'listing' => $listing,
                'price' => $listing->effectivePrice(),
                'grams' => $fees->billableGrams($listing->variant),
                'cubic_feet' => $cubicFeet,
                'outbound' => $outbound,
                'storage' => $fees->storage($cubicFeet, 'storage', $currency),
                'removal' => $fees->removal(RemovalType::ReturnToSeller, [['listing' => $listing, 'quantity' => 1]], $currency),
            ];
        }

        return view('panels.partner.inventory.fees', [
            'fees' => $this->settings->fees(),
            'currency' => $currency,
            'listings' => $this->fbnListings(),
            'preview' => $preview,
            'invoices' => StorageInvoice::query()->where('vendor_id', $vendor->id)->latest('period_start')->limit(12)->get(),
            'removalFees' => VendorBalanceItem::query()->where('vendor_id', $vendor->id)->where('type', VendorBalanceItem::TYPE_FBN_REMOVAL)->latest('id')->limit(12)->get(),
            'orderFees' => SubOrderSettlement::query()->where('vendor_id', $vendor->id)->where('fbn_fees', '>', 0)->with('subOrder:id,number')->latest('id')->limit(12)->get(),
        ]);
    }

    private function ship(Request $request, InboundShipment $shipment, int $userId): InboundShipment
    {
        $data = $request->validate([
            'carrier_name' => ['required', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->inbound->markShipped($shipment, $data['carrier_name'], $data['tracking_number'] ?? null, $userId);
    }

    /**
     * @return Collection<int, Listing>
     */
    private function fbnListings(): Collection
    {
        return Listing::query()->where('vendor_id', $this->workspace->vendor()->id)->where('fulfilment', 'fbn')
            ->where('status', '!=', ListingStatus::Archived)->with('variant.product')->orderBy('id')->get();
    }

    private function ownListing(int $id): Listing
    {
        return Listing::query()->where('vendor_id', $this->workspace->vendor()->id)->with('variant.product')->findOrFail($id);
    }

    private function ownShipment(int $id): InboundShipment
    {
        return InboundShipment::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
    }
}
