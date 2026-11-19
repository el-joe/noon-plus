<?php

namespace App\Domains\Delivery\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\CarrierStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\ParcelLabels;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use App\Shared\StateMachine\StateTransition;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Delivery & logistics → Dispatch (SRS §4.1.11 "Delivery
 * Assignments"): the ready-to-dispatch queue (manual or automatic
 * assignment), failed shipments (reschedule / return to origin), parcels
 * on their way back, and every assignment with its history and proof.
 */
class DeliveryDispatchController extends Controller
{
    public function __construct(private CountryContext $countryContext, private DeliveryDispatch $dispatch) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AssignmentStatus::class)],
            // "Ready to dispatch" filters (QA-C08): paged board, not the 50 oldest.
            'q' => ['nullable', 'string', 'max:60'],
            'stage' => ['nullable', Rule::in([SubOrderStatus::Packed->value, SubOrderStatus::Shipped->value, SubOrderStatus::OutForDelivery->value])],
            'zone' => ['nullable', 'integer'],
            'city' => ['nullable', 'string', 'max:80'],
            'seller' => ['nullable', 'integer'],
            'carrier' => ['nullable', 'regex:/^(none|\d+)$/'],
            'payment' => ['nullable', Rule::in(['cod', 'prepaid'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $ready = $this->readyQuery($filters)
            ->with(['order', 'vendor'])->orderBy('id')
            ->paginate(25, ['*'], 'ready_page')->withQueryString();

        return view('panels.admin.delivery.dispatch', [
            'ready' => $ready,
            'attention' => Shipment::query()->whereIn('status', [ShipmentStatus::Failed, ShipmentStatus::ReturningToOrigin])->with('subOrder.order')->orderBy('id')->get(),
            'assignments' => DeliveryAssignment::query()
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->with(['agent', 'subOrder'])->latest('id')->paginate(25)->withQueryString(),
            'agents' => DeliveryAgent::query()->where('status', AgentStatus::Active)->orderBy('name')->get(),
            'carriers' => Carrier::query()->where('status', CarrierStatus::Active)->where('is_internal', false)->orderBy('name')->pluck('name', 'id'),
            'allocated' => Shipment::query()->whereIn('sub_order_id', $ready->pluck('id'))->whereNotNull('carrier_id')->pluck('carrier_id', 'sub_order_id'),
            'zones' => ShippingZone::query()->orderBy('name')->pluck('name', 'id'),
            'sellers' => Vendor::query()->whereIn('id', $this->readyQuery([])->select('vendor_id'))->orderBy('store_name')->pluck('store_name', 'id'),
            'allCarriers' => Carrier::query()->where('is_internal', false)->orderBy('name')->pluck('name', 'id'),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'clock' => app(CountryClock::class),
        ]);
    }

    /**
     * Parcels waiting for an agent (packed or handed over, no open
     * assignment, shipment not yet moving), narrowed by the board filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<SubOrder>
     */
    private function readyQuery(array $filters): Builder
    {
        $blocked = Shipment::query()->whereNotIn('status', [ShipmentStatus::LabelCreated->value, ShipmentStatus::Rescheduled->value])->select('sub_order_id');
        $busy = DeliveryAssignment::query()->whereIn('status', AssignmentStatus::open())->select('sub_order_id');
        $adminZone = config('nawy.admin_timezone');
        $orders = fn (Closure $scope) => fn (Builder $query) => $query->whereIn('order_id', $scope(Order::query())->select('id'));

        return SubOrder::query()
            ->whereIn('status', [SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery])
            ->whereNotIn('id', $blocked)->whereNotIn('id', $busy)
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $match) => $match
                ->where('number', 'like', LikeSearch::contains($search))
                ->orWhereIn('order_id', Order::query()->where('number', 'like', LikeSearch::contains($search))->select('id'))))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $stage) => $query->where('status', $stage))
            ->when($filters['zone'] ?? null, fn (Builder $query, int|string $zone) => $query->where('shipping_zone_id', (int) $zone))
            ->when($filters['seller'] ?? null, fn (Builder $query, int|string $seller) => $query->where('vendor_id', (int) $seller))
            ->when($filters['city'] ?? null, $orders(fn (Builder $query) => $query->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.city'))) like ?", [LikeSearch::contains(mb_strtolower($filters['city']))])))
            ->when(($filters['payment'] ?? null) === 'cod', $orders(fn (Builder $query) => $query->where('payment_method', 'cod')))
            ->when(($filters['payment'] ?? null) === 'prepaid', $orders(fn (Builder $query) => $query->where('payment_method', '!=', 'cod')))
            ->when($filters['from'] ?? null, $orders(fn (Builder $query) => $query->where('placed_at', '>=', CarbonImmutable::parse($filters['from'], $adminZone)->startOfDay()->utc())))
            ->when($filters['to'] ?? null, $orders(fn (Builder $query) => $query->where('placed_at', '<', CarbonImmutable::parse($filters['to'], $adminZone)->addDay()->startOfDay()->utc())))
            ->when(($filters['carrier'] ?? null) === 'none', fn (Builder $query) => $query->whereNotIn('id', Shipment::query()->whereNotNull('carrier_id')->select('sub_order_id')))
            ->when(($filters['carrier'] ?? 'none') !== 'none', fn (Builder $query) => $query->whereIn('id', Shipment::query()->where('carrier_id', (int) $filters['carrier'])->select('sub_order_id')));
    }

    public function show(int $assignment, AgentAssignments $agentAssignments): View
    {
        $assignment = DeliveryAssignment::query()->with(['agent', 'carrier', 'shipment', 'subOrder.order', 'subOrder.vendor'])->findOrFail($assignment);

        return view('panels.admin.delivery.assignment', [
            'assignment' => $assignment,
            'history' => StateTransition::query()->where('subject_type', $assignment->transitionSubject())->where('subject_id', $assignment->id)->orderBy('id')->get(),
            'shipmentHistory' => StateTransition::query()->where('subject_type', 'shipment')->where('subject_id', $assignment->shipment_id)->orderBy('id')->get(),
            'agents' => DeliveryAgent::query()->where('status', AgentStatus::Active)->whereKeyNot($assignment->delivery_agent_id)->orderBy('name')->get(),
            'needsProof' => $agentAssignments->needsProof($assignment),
            'country' => $this->countryContext->current(),
            'clock' => app(CountryClock::class),
        ]);
    }

    public function assign(Request $request, int $subOrder): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required_without:auto', 'nullable', 'integer'], 'auto' => ['nullable', 'boolean']]);
        $subOrder = SubOrder::query()->findOrFail($subOrder);

        $assignment = ($data['auto'] ?? false)
            ? $this->dispatch->autoAssign($subOrder, ActorType::Admin, $request->user()->id)
            : $this->dispatch->assign($subOrder, DeliveryAgent::query()->findOrFail($data['agent_id']), ActorType::Admin, $request->user()->id);

        return back()->with('status', __(':number assigned to :agent.', ['number' => $subOrder->number, 'agent' => $assignment->agent->name]));
    }

    /**
     * Hand a ready parcel to a shipping company (its supervisors assign it).
     */
    public function allocate(Request $request, int $subOrder): RedirectResponse
    {
        $data = $request->validate(['carrier_id' => ['required', 'integer']]);
        $subOrder = SubOrder::query()->findOrFail($subOrder);
        $carrier = Carrier::query()->findOrFail($data['carrier_id']);
        $this->dispatch->allocate($subOrder, $carrier, ActorType::Admin, $request->user()->id);

        return back()->with('status', __(':number handed to :carrier.', ['number' => $subOrder->number, 'carrier' => $carrier->name]));
    }

    public function reassign(Request $request, int $assignment): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:255']]);
        $new = $this->dispatch->reassign(DeliveryAssignment::query()->findOrFail($assignment), DeliveryAgent::query()->findOrFail($data['agent_id']), ActorType::Admin, $request->user()->id, null, $data['reason'] ?? null);

        return redirect()->route('admin.delivery.assignments.show', $new->id)->with('status', __('Reassigned with a new delivery code.'));
    }

    public function reschedule(Request $request, int $shipment): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $this->dispatch->reschedule(Shipment::query()->findOrFail($shipment), $data['date'], ActorType::Admin, $request->user()->id);

        return back()->with('status', __('Delivery rescheduled; assign the next attempt.'));
    }

    public function returnToOrigin(Request $request, int $shipment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->dispatch->returnToOrigin(Shipment::query()->findOrFail($shipment), ActorType::Admin, $request->user()->id, $data['reason']);

        return back()->with('status', __('The shipment is on its way back to the origin.'));
    }

    public function confirmReturned(Request $request, int $shipment): RedirectResponse
    {
        $this->dispatch->confirmReturned(Shipment::query()->findOrFail($shipment), ActorType::Admin, $request->user()->id);

        return back()->with('status', __('Returned to origin — the order was cancelled and refunded where paid.'));
    }

    public function proof(int $assignment, string $kind): StreamedResponse
    {
        $assignment = DeliveryAssignment::query()->findOrFail($assignment);
        $path = $kind === 'signature' ? $assignment->signature_path : $assignment->proof_photo_path;

        abort_if($path === null, 404);

        return Storage::disk(AgentAssignments::DISK)->response($path);
    }

    /**
     * Parcel label of a shipment (HTML to print, or `.pdf`).
     */
    public function label(Request $request, int $shipment, ParcelLabels $labels): Response|\Illuminate\Contracts\View\View
    {
        $shipment = Shipment::query()->findOrFail($shipment);

        return $request->routeIs('admin.delivery.shipments.label-pdf') ? $labels->pdf($shipment) : $labels->view($shipment);
    }
}
