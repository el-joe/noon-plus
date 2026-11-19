<?php

namespace App\Domains\Fulfilment\Http\Controllers\Seller;

use App\Domains\Fulfilment\Models\PackagingOrder;
use App\Domains\Fulfilment\Models\PackagingSupply;
use App\Domains\Fulfilment\Support\PackagingOrders;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Inventory → Packaging supplies (SRS §4.1.3, lifecycle
 * catalogue §6.3): the country's packaging catalogue with an order form
 * (quantities, delivery address; cost + delivery fee deducted from the next
 * payout) and the store's packaging orders with their status.
 */
class SellerPackagingController extends Controller
{
    public function __construct(private VendorWorkspace $workspace, private PackagingOrders $orders) {}

    public function index(): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.packaging.index', [
            'supplies' => PackagingSupply::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'orders' => PackagingOrder::query()->where('vendor_id', $vendor->id)->withCount('items')->latest('id')->paginate(15),
            'deliveryFee' => $this->orders->deliveryFee(),
            'address' => $vendor->business_details['address'] ?? [],
        ]);
    }

    public function show(int $order): View
    {
        return view('panels.partner.packaging.show', [
            'order' => PackagingOrder::query()->where('vendor_id', $this->workspace->vendor()->id)->with(['items', 'transitions'])->findOrFail($order),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'address.street' => ['required', 'string', 'max:160'],
            'address.area' => ['required', 'string', 'max:120'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.building' => ['nullable', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $order = $this->orders->place($this->workspace->vendor(), $request->user()->id, $data['quantities'], $data['address'], $data['notes'] ?? null);
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return redirect()->route('partner.packaging.show', $order->id)->with('status', __('Packaging order :number placed. The total is deducted from your next payout.', ['number' => $order->number]));
    }
}
