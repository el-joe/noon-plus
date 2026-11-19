<?php

namespace App\Domains\Fulfilment\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\RemovalStatus;
use App\Domains\Fulfilment\Enums\StockBucket;
use App\Domains\Fulfilment\Enums\TransferStatus;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\RemovalOrder;
use App\Domains\Fulfilment\Models\StockTransfer;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Models\WarehouseStockMovement;
use App\Domains\Fulfilment\Support\FbnOrders;
use App\Domains\Fulfilment\Support\FbnSettings;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Models\Listing;
use App\Domains\Shipping\Models\ShippingZone;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Inventory & fulfilment (Fulfilled by Nawy): overview, warehouses
 * (CRUD), warehouse stock with adjustments (reason + note, audited as
 * movements) and the country's FBN fee table.
 */
class FbnAdminController extends Controller
{
    public const ADJUSTMENT_REASONS = ['cycle_count', 'found', 'lost', 'damaged_in_warehouse', 'correction'];

    public function __construct(private CountryContext $countryContext, private WarehouseStockService $stock, private FbnSettings $settings) {}

    public function overview(FbnOrders $orders): View
    {
        $warehouses = Warehouse::query()->withSum('stock', 'on_hand')->withSum('stock', 'reserved')->withSum('stock', 'damaged')->orderBy('code')->get();

        return view('panels.admin.inventory.overview', [
            'country' => $this->countryContext->current(),
            'warehouses' => $warehouses,
            'counts' => [
                'inbound' => InboundShipment::query()->whereIn('status', [InboundStatus::Submitted, InboundStatus::InTransit, InboundStatus::Arrived, InboundStatus::Receiving])->count(),
                'arrived' => InboundShipment::query()->whereIn('status', [InboundStatus::Arrived, InboundStatus::Receiving])->count(),
                'pick_pack' => $orders->queue()->count(),
                'transfers' => StockTransfer::query()->whereIn('status', [TransferStatus::Requested, TransferStatus::InTransit])->count(),
                'removals' => RemovalOrder::query()->whereIn('status', [RemovalStatus::Requested, RemovalStatus::Processing])->count(),
                'skus' => WarehouseStock::query()->where('on_hand', '>', 0)->count(),
                'long_term' => WarehouseStock::query()->whereNotNull('long_term_flagged_at')->count(),
                'unsellable' => WarehouseStock::query()->whereNotNull('unsellable_flagged_at')->count(),
            ],
            'recent' => WarehouseStockMovement::query()->with(['warehouse', 'listing.variant'])->latest('id')->limit(10)->get(),
        ]);
    }

    public function warehouses(): View
    {
        return view('panels.admin.inventory.warehouses', [
            'country' => $this->countryContext->current(),
            'warehouses' => Warehouse::query()->with('zone')->withSum('stock', 'on_hand')->orderBy('code')->get(),
            'zones' => ShippingZone::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $warehouse = Warehouse::query()->create($this->warehouseData($request));

        return back()->with('status', __('Warehouse :code added.', ['code' => $warehouse->code]));
    }

    public function updateWarehouse(Request $request, int $warehouse): RedirectResponse
    {
        $warehouse = Warehouse::query()->findOrFail($warehouse);
        $warehouse->update($this->warehouseData($request, $warehouse->id));

        return back()->with('status', __('Warehouse :code saved.', ['code' => $warehouse->code]));
    }

    public function stock(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
            'flag' => ['nullable', Rule::in(['damaged', 'long_term', 'unsellable', 'reserved'])],
        ]);

        return view('panels.admin.inventory.stock', [
            'rows' => WarehouseStock::query()->with(['warehouse', 'listing.variant.product', 'listing.vendor'])
                ->when($filters['warehouse_id'] ?? null, fn ($query, int $id) => $query->where('warehouse_id', $id))
                ->when($filters['q'] ?? null, fn ($query, string $search) => $query->whereHas('listing', fn ($listing) => $listing
                    ->whereHas('variant', fn ($variant) => $variant->where('sku', 'like', LikeSearch::contains($search)))
                    ->orWhereHas('vendor', fn ($vendor) => $vendor->where('store_name', 'like', LikeSearch::contains($search)))))
                ->when($filters['flag'] ?? null, fn ($query, string $flag) => match ($flag) {
                    'damaged' => $query->where('damaged', '>', 0),
                    'reserved' => $query->where('reserved', '>', 0),
                    'long_term' => $query->whereNotNull('long_term_flagged_at'),
                    default => $query->whereNotNull('unsellable_flagged_at'),
                })
                ->orderBy('warehouse_id')->orderBy('listing_id')->paginate(30)->withQueryString(),
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'filters' => $filters,
            'reasons' => self::ADJUSTMENT_REASONS,
            'movements' => WarehouseStockMovement::query()->with(['warehouse', 'listing.variant'])->where('reason', 'like', 'adjustment%')->orWhere('reason', 'marked_damaged')->latest('id')->limit(20)->get(),
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'sku' => ['required', 'string', 'max:64'],
            'bucket' => ['required', Rule::in([StockBucket::OnHand->value, StockBucket::Damaged->value, 'mark_damaged'])],
            'quantity' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', Rule::in(self::ADJUSTMENT_REASONS)],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $listing = $this->fbnListingBySku($data['sku']);

        if ($data['bucket'] === 'mark_damaged') {
            $this->stock->markDamaged($warehouse, $listing, abs((int) $data['quantity']), $data['note'], $request->user()->id);
        } else {
            $this->stock->adjust($warehouse, $listing, StockBucket::from($data['bucket']), (int) $data['quantity'], $data['reason'], $data['note'], $request->user()->id);
        }

        return back()->with('status', __('Stock of :sku adjusted.', ['sku' => $data['sku']]));
    }

    public function fees(): View
    {
        return view('panels.admin.inventory.fees', [
            'country' => $this->countryContext->current(),
            'fees' => $this->settings->fees(),
            'defaults' => $this->settings->defaultsFor($this->countryContext->current()->currency),
        ]);
    }

    public function updateFees(Request $request): RedirectResponse
    {
        $amount = ['required', 'numeric', 'min:0', 'max:100000'];
        $data = $request->validate(array_fill_keys(FbnSettings::FEE_KEYS, $amount) + [
            'outbound_tiers' => ['required', 'array', 'min:1', 'max:20'],
            'outbound_tiers.*.max_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'outbound_tiers.*.low' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'outbound_tiers.*.high' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $this->settings->saveFees($data, $request->user()->id);

        return back()->with('status', __('FBN fees saved for :country.', ['country' => $this->countryContext->current()->name_en]));
    }

    /**
     * @return array<string, mixed>
     */
    private function warehouseData(Request $request, ?int $ignoreId = null): array
    {
        $connection = Warehouse::query()->getConnection()->getName();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9-]+$/', Rule::unique($connection.'.warehouses', 'code')->ignore($ignoreId)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'shipping_zone_id' => ['nullable', 'integer', Rule::exists($connection.'.shipping_zones', 'id')],
            'capacity_cubic_feet' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function fbnListingBySku(string $sku): Listing
    {
        return Listing::query()->where('fulfilment', 'fbn')->with('variant')
            ->whereHas('variant', fn ($variant) => $variant->where('sku', $sku))
            ->orWhere(fn ($query) => $query->where('fulfilment', 'fbn')->where('vendor_sku', $sku))
            ->firstOr(fn () => throw ValidationException::withMessages(['sku' => __('No FBN product has this SKU.')]));
    }
}
