<?php

namespace App\Domains\Orders\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\InvoicePdf;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Orders\Support\SubOrderTimeline;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Orders (SRS §4.1.4; Figma 97:1164): orders of the selected country
 * filtered by status and by placement date in Oman time, the order detail
 * with each sub-order's state timeline, admin status moves (reason required)
 * and force-cancel. All changes go through the SubOrderStateMachine.
 */
class AdminOrderController extends Controller
{
    public function __construct(
        private SubOrderStateMachine $machine,
        private CountryContext $countryContext,
        private CountryClock $clock,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $adminZone = config('nawy.admin_timezone');

        $orders = app(AdminVendorScope::class)->applyThrough(Order::query(), 'subOrders')
            ->withCount('subOrders')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where('number', 'like', LikeSearch::contains($search)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('placed_at', '>=', CarbonImmutable::parse($from, $adminZone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('placed_at', '<', CarbonImmutable::parse($to, $adminZone)->addDay()->startOfDay()->utc()))
            ->latest('placed_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('panels.admin.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    public function show(int $order, SubOrderTimeline $timeline): View
    {
        $order = app(AdminVendorScope::class)->applyThrough(Order::query(), 'subOrders')->with(['subOrders' => fn ($subOrders) => app(AdminVendorScope::class)->apply($subOrders), 'subOrders.items', 'subOrders.vendor', 'subOrders.statusHistory'])->findOrFail($order);

        return view('panels.admin.orders.show', [
            'order' => $order,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
            'timelines' => $order->subOrders->mapWithKeys(fn (SubOrder $subOrder): array => [
                $subOrder->id => $timeline->steps($subOrder, config('nawy.admin_timezone'), 'Oman'),
            ]),
            'moves' => $order->subOrders->mapWithKeys(fn (SubOrder $subOrder): array => [
                $subOrder->id => array_values(array_filter($subOrder->status->allowedNext(), fn (SubOrderStatus $status): bool => $status !== SubOrderStatus::Cancelled)),
            ]),
        ]);
    }

    /**
     * D16: the customer's tax invoice as a PDF. An admin scoped to some
     * sellers only gets it when every shipment of the order is in scope.
     */
    public function invoicePdf(int $order, InvoicePdf $pdf): Response
    {
        $scope = app(AdminVendorScope::class);
        $order = $scope->applyThrough(Order::query(), 'subOrders')->with(['subOrders.items', 'subOrders.vendor'])->findOrFail($order);

        abort_if($scope->apply($order->subOrders()->getQuery())->count() !== $order->subOrders->count(), 403);
        abort_unless(InvoicePdf::isReady($order), 409, __('The invoice is ready once the order is paid.'));

        return $pdf->download($pdf->forOrder($order, $this->countryContext->current(), User::query()->findOrFail($order->customer_user_id)), $order->number);
    }

    public function move(Request $request, int $subOrder): RedirectResponse
    {
        $subOrder = app(AdminVendorScope::class)->apply(SubOrder::query())->findOrFail($subOrder);
        $data = $request->validate([
            'to' => ['required', Rule::enum(SubOrderStatus::class), Rule::notIn([SubOrderStatus::Cancelled->value])],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            $this->machine->transition($subOrder, SubOrderStatus::from($data['to']), ActorType::Admin, $request->user()->id, $data['reason']);
        } catch (SubOrderTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Sub-order :number moved to :status.', ['number' => $subOrder->number, 'status' => str_replace('_', ' ', $data['to'])]));
    }

    public function forceCancel(Request $request, int $subOrder): RedirectResponse
    {
        $subOrder = app(AdminVendorScope::class)->apply(SubOrder::query())->findOrFail($subOrder);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        try {
            $this->machine->transition($subOrder, SubOrderStatus::Cancelled, ActorType::Admin, $request->user()->id, $reason);
        } catch (SubOrderTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Sub-order :number was force-cancelled.', ['number' => $subOrder->number]));
    }
}
