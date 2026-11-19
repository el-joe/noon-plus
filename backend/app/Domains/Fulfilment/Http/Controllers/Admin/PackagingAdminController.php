<?php

namespace App\Domains\Fulfilment\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Enums\PackagingOrderStatus;
use App\Domains\Fulfilment\Models\PackagingOrder;
use App\Domains\Fulfilment\Models\PackagingSupply;
use App\Domains\Fulfilment\Support\PackagingOrders;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Operations → Packaging (SRS §4.1.3, lifecycle catalogue §6.3):
 * the country's packaging catalogue (items, unit cost, order limits, active)
 * and delivery fee, and the stores' packaging orders: approve or reject
 * (the charge is given back), ship with carrier and tracking, deliver.
 */
class PackagingAdminController extends Controller
{
    public function __construct(private PackagingOrders $orders, private CountryContext $countryContext, private AdminVendorScope $scope) {}

    public function supplies(): View
    {
        return view('panels.admin.packaging.supplies', [
            'supplies' => PackagingSupply::query()->orderBy('sort_order')->orderBy('id')->get(),
            'deliveryFee' => $this->orders->deliveryFee(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storeSupply(Request $request): RedirectResponse
    {
        PackagingSupply::query()->create($this->validated($request, null));

        return back()->with('status', __('Packaging item added.'));
    }

    public function editSupply(int $supply): View
    {
        return view('panels.admin.packaging.supply-form', ['supply' => PackagingSupply::query()->findOrFail($supply), 'country' => $this->countryContext->current()]);
    }

    public function updateSupply(Request $request, int $supply): RedirectResponse
    {
        $supply = PackagingSupply::query()->findOrFail($supply);
        $supply->update($this->validated($request, $supply));

        return redirect()->route('admin.packaging')->with('status', __('Packaging item saved.'));
    }

    public function deliveryFee(Request $request, SettingsService $settings): RedirectResponse
    {
        $data = $request->validate(['delivery_fee' => ['required', 'decimal:0,3', 'min:0', 'max:100000']]);
        $settings->set(PackagingOrders::DELIVERY_FEE_SETTING, (string) $data['delivery_fee'], $this->countryContext->current(), $request->user()->id);

        return back()->with('status', __('Delivery fee saved.'));
    }

    public function orders(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(PackagingOrderStatus::class)]]);

        return view('panels.admin.packaging.orders', [
            'orders' => $this->scope->apply(PackagingOrder::query())->with('vendor')->withCount('items')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function order(int $order): View
    {
        return view('panels.admin.packaging.order', [
            'order' => $this->scope->apply(PackagingOrder::query())->with(['vendor', 'items', 'transitions'])->findOrFail($order),
        ]);
    }

    public function move(Request $request, int $order, string $action): RedirectResponse
    {
        $order = $this->scope->apply(PackagingOrder::query())->findOrFail($order);
        $data = $request->validate([
            'reason' => [Rule::requiredIf($action === 'reject'), 'nullable', 'string', 'min:5', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
        ]);
        $adminId = $request->user()->id;

        try {
            match ($action) {
                'approve' => $this->orders->approve($order, $adminId),
                'reject' => $this->orders->reject($order, $adminId, (string) $data['reason']),
                'ship' => $this->orders->ship($order, $adminId, $data['carrier_name'] ?? null, $data['tracking_number'] ?? null),
                default => $this->orders->deliver($order, $adminId),
            };
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Packaging order updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PackagingSupply $supply): array
    {
        $data = $request->validate([
            'sku' => ['required', 'alpha_dash', 'max:48', Rule::unique((new PackagingSupply)->getConnectionName().'.packaging_supplies', 'sku')->ignore($supply?->id)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'unit_cost' => ['required', 'decimal:0,3', 'min:0', 'max:100000'],
            'min_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'max_quantity' => ['nullable', 'integer', 'gte:min_quantity', 'max:100000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        return ['sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active')] + $data;
    }
}
