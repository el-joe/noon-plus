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
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AssignmentStatus::class)]]);
        $blocked = Shipment::query()->whereNotIn('status', [ShipmentStatus::LabelCreated->value, ShipmentStatus::Rescheduled->value])->select('sub_order_id');
        $busy = DeliveryAssignment::query()->whereIn('status', AssignmentStatus::open())->select('sub_order_id');

        $ready = SubOrder::query()
            ->whereIn('status', [SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery])
            ->whereNotIn('id', $blocked)->whereNotIn('id', $busy)
            ->with(['order', 'vendor'])->orderBy('id')->limit(50)->get();

        return view('panels.admin.delivery.dispatch', [
            'ready' => $ready,
            'attention' => Shipment::query()->whereIn('status', [ShipmentStatus::Failed, ShipmentStatus::ReturningToOrigin])->with('subOrder.order')->orderBy('id')->get(),
            'assignments' => DeliveryAssignment::query()
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->with(['agent', 'subOrder'])->latest('id')->paginate(25)->withQueryString(),
            'agents' => DeliveryAgent::query()->where('status', AgentStatus::Active)->orderBy('name')->get(),
            'carriers' => Carrier::query()->where('status', CarrierStatus::Active)->where('is_internal', false)->orderBy('name')->pluck('name', 'id'),
            'allocated' => Shipment::query()->whereIn('sub_order_id', $ready->pluck('id'))->whereNotNull('carrier_id')->pluck('carrier_id', 'sub_order_id'),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'clock' => app(CountryClock::class),
        ]);
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
}
