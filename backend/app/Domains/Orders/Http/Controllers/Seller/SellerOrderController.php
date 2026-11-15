<?php

namespace App\Domains\Orders\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SellerFulfilment;
use App\Domains\Orders\Support\SubOrderTimeline;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller Center → Orders (SRS §4.3 My Orders, §7.1; Figma 90:2170): the
 * store's sub-orders, the fulfilment steps, cancellation with a strike and a
 * printable packing slip. Every step goes through the SubOrderStateMachine.
 */
class SellerOrderController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private SellerFulfilment $fulfilment,
        private CountryContext $countryContext,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(SubOrderStatus::class)],
        ]);

        $subOrders = SubOrder::query()
            ->where('vendor_id', $this->workspace->vendor()->id)
            ->with('order:id,placed_at')
            ->withCount('items')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('number', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('panels.partner.orders.index', [
            'subOrders' => $subOrders,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $subOrder, SubOrderTimeline $timeline): View
    {
        $subOrder = $this->ownSubOrder($subOrder);
        $subOrder->load(['order', 'items', 'statusHistory']);
        $country = $this->countryContext->current();
        $next = $this->fulfilment->nextStep($subOrder->status);

        return view('panels.partner.orders.show', [
            'subOrder' => $subOrder,
            'country' => $country,
            'nextStep' => $next,
            'nextStepLabel' => $next ? $this->fulfilment->stepLabel($next) : null,
            'canCancel' => $this->fulfilment->canCancel($subOrder),
            'steps' => $timeline->steps($subOrder, $country->timezone, ''),
            'strikePoints' => (int) config('nawy.vendors.cancellation_strike_points'),
        ]);
    }

    public function packingSlip(int $subOrder): View
    {
        $subOrder = $this->ownSubOrder($subOrder);
        $subOrder->load(['order', 'items']);

        return view('panels.partner.orders.packing-slip', [
            'subOrder' => $subOrder,
            'vendor' => $this->workspace->vendor(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function advance(Request $request, int $subOrder): RedirectResponse
    {
        $subOrder = $this->ownSubOrder($subOrder);
        $to = SubOrderStatus::from($request->validate(['to' => ['required', Rule::enum(SubOrderStatus::class)]])['to']);

        try {
            $this->fulfilment->advance($subOrder, $to, $request->user()->id);
        } catch (SubOrderTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Order :number is now :status.', ['number' => $subOrder->number, 'status' => str_replace('_', ' ', $to->value)]));
    }

    public function cancel(Request $request, int $subOrder): RedirectResponse
    {
        $subOrder = $this->ownSubOrder($subOrder);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        try {
            $this->fulfilment->cancel($subOrder, $reason, $request->user()->id);
        } catch (SubOrderTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Order :number was cancelled. A strike was added to your seller score.', ['number' => $subOrder->number]));
    }

    /**
     * The store's own sub-order, or 404 for anyone else's (matrix §4 T4).
     */
    private function ownSubOrder(int $id): SubOrder
    {
        $subOrder = SubOrder::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
        Gate::authorize('manage', $subOrder);

        return $subOrder;
    }
}
