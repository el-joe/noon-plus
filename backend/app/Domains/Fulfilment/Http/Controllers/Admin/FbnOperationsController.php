<?php

namespace App\Domains\Fulfilment\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Models\FbnReservation;
use App\Domains\Fulfilment\Models\InboundLine;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\RemovalOrder;
use App\Domains\Fulfilment\Models\StockTransfer;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Support\FbnOrders;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Fulfilment\Support\RemovalOrders;
use App\Domains\Fulfilment\Support\StockTransfers;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Http\Controllers\Controller;
use App\Shared\Localization\Label;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Inventory & fulfilment, warehouse operations: inbound receiving
 * queue (arrive, receive lines into bins, finish, close), pick & pack queue
 * of FBN orders, transfers between warehouses and removal orders.
 */
class FbnOperationsController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function inbound(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(InboundStatus::class)], 'warehouse_id' => ['nullable', 'integer']]);

        return view('panels.admin.inventory.inbound', [
            'shipments' => InboundShipment::query()->with(['warehouse', 'vendor'])->withSum('lines', 'quantity_expected')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status), fn ($query) => $query->whereIn('status', [InboundStatus::Submitted, InboundStatus::InTransit, InboundStatus::Arrived, InboundStatus::Receiving, InboundStatus::Received]))
                ->when($filters['warehouse_id'] ?? null, fn ($query, int $id) => $query->where('warehouse_id', $id))
                ->orderByRaw("case status when 'receiving' then 0 when 'arrived' then 1 when 'in_transit' then 2 when 'submitted' then 3 else 4 end")
                ->orderBy('expected_at')->orderBy('id')->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'filters' => $filters,
        ]);
    }

    public function showInbound(int $shipment): View
    {
        return view('panels.admin.inventory.inbound-show', [
            'shipment' => InboundShipment::query()->with(['lines.listing.variant.product', 'warehouse', 'vendor', 'transitions'])->findOrFail($shipment),
        ]);
    }

    public function actInbound(Request $request, int $shipment, string $action, InboundShipments $inbound): RedirectResponse
    {
        $shipment = InboundShipment::query()->findOrFail($shipment);
        $userId = $request->user()->id;

        match ($action) {
            'arrive' => $inbound->markArrived($shipment, $userId),
            'receive' => $inbound->startReceiving($shipment, $userId),
            'finish' => $inbound->finishReceiving($shipment, $userId),
            'close' => $inbound->close($shipment, $userId),
            'cancel' => $inbound->cancel($shipment, ActorType::Admin, $userId, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason']),
        };

        return back()->with('status', __('Inbound :number updated.', ['number' => $shipment->number]));
    }

    public function receiveLine(Request $request, int $line, InboundShipments $inbound): RedirectResponse
    {
        $data = $request->validate([
            'good' => ['required', 'integer', 'min:0', 'max:100000'],
            'damaged' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'bin_code' => ['nullable', 'string', 'max:32'],
        ]);
        $line = InboundLine::query()->with('listing.variant')->findOrFail($line);
        $inbound->receiveLine($line, (int) $data['good'], (int) ($data['damaged'] ?? 0), $data['bin_code'] ?? null, $request->user()->id);

        return back()->with('status', __(':sku: :good received, :damaged damaged.', ['sku' => $line->listing->variant->sku, 'good' => (int) $data['good'], 'damaged' => (int) ($data['damaged'] ?? 0)]));
    }

    public function pickPack(FbnOrders $orders): View
    {
        $queue = $orders->queue()->with(['items', 'order:id,placed_at,payment_method,payment_status'])->orderBy('id')->paginate(30);
        $reservations = FbnReservation::query()->whereIn('sub_order_id', $queue->getCollection()->pluck('id'))->with(['warehouse'])->get()->groupBy('sub_order_id');
        $bins = WarehouseStock::query()->whereIn('listing_id', $reservations->flatten()->pluck('listing_id'))->get()->keyBy(fn ($row) => $row->warehouse_id.':'.$row->listing_id);

        return view('panels.admin.inventory.pick-pack', [
            'queue' => $queue,
            'reservations' => $reservations,
            'bins' => $bins,
            'orders' => $orders,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function advanceOrder(Request $request, int $subOrder, FbnOrders $orders): RedirectResponse
    {
        $subOrder = SubOrder::query()->findOrFail($subOrder);
        $to = SubOrderStatus::from($request->validate(['to' => ['required', Rule::enum(SubOrderStatus::class)]])['to']);

        try {
            $orders->advance($subOrder, $to, $request->user()->id);
        } catch (SubOrderTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Order :number moved to :status.', ['number' => $subOrder->number, 'status' => Label::of($to)]));
    }

    public function transfers(): View
    {
        return view('panels.admin.inventory.transfers', [
            'transfers' => StockTransfer::query()->with(['from', 'to', 'lines.listing.variant'])->latest('id')->paginate(20),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function storeTransfer(Request $request, StockTransfers $transfers): RedirectResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:30'],
            'lines.*.sku' => ['nullable', 'string', 'max:64'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $lines = [];

        foreach ($data['lines'] as $line) {
            if (empty($line['sku']) || empty($line['quantity'])) {
                continue;
            }

            $listing = Listing::query()->where('fulfilment', 'fbn')->whereHas('variant', fn ($variant) => $variant->where('sku', $line['sku']))->first()
                ?? throw ValidationException::withMessages(['lines' => __('No FBN product has the SKU :sku.', ['sku' => $line['sku']])]);
            $lines[] = ['listing_id' => $listing->id, 'quantity' => (int) $line['quantity']];
        }

        $transfer = $transfers->request((int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'], $lines, $data['note'] ?? null, $request->user()->id);

        return back()->with('status', __('Transfer :number requested.', ['number' => $transfer->number]));
    }

    public function actTransfer(Request $request, int $transfer, string $action, StockTransfers $transfers): RedirectResponse
    {
        $transfer = StockTransfer::query()->findOrFail($transfer);
        $userId = $request->user()->id;

        match ($action) {
            'dispatch' => $transfers->dispatch($transfer, $userId),
            'receive' => $transfers->receive($transfer, array_map('intval', (array) $request->input('received', [])), $userId),
            'cancel' => $transfers->cancel($transfer, $userId),
        };

        return back()->with('status', __('Transfer :number updated.', ['number' => $transfer->number]));
    }

    public function removals(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(RemovalStatus::class)]])['status'] ?? null;

        return view('panels.admin.inventory.removals', [
            'removals' => RemovalOrder::query()->with(['vendor', 'warehouse', 'lines.listing.variant'])
                ->when($status, fn ($query, string $value) => $query->where('status', $value))->latest('id')->paginate(25)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function actRemoval(Request $request, int $removal, string $action, RemovalOrders $removals): RedirectResponse
    {
        $order = RemovalOrder::query()->findOrFail($removal);
        $userId = $request->user()->id;

        match ($action) {
            'process' => $removals->process($order, $userId),
            'complete' => $removals->complete($order, $userId),
            'reject' => $removals->cancel($order, ActorType::Admin, $userId, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason']),
        };

        return back()->with('status', __('Removal :number updated.', ['number' => $order->number]));
    }
}
