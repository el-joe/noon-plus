<?php

namespace App\Domains\Delivery\Http\Controllers\Carrier;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Http\Middleware\ResolveCarrier;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\CarrierBoard;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Delivery\Support\Scorecards;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\RoleMembership;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\TeamInvitations;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shipping panel (carrier., SRS §4.7, role-permission matrix §8, Figma
 * "Shipping overview / shipments & pickups / COD reconciliation / claims &
 * scorecard" and "Supervisor dispatch / failed deliveries"). Every page is
 * scoped to the supervisor's company: its handed-over parcels, its agents,
 * its COD and its claims. Actions go through the delivery services with
 * the carrier as actor and acting company (guards refuse anything else).
 */
class CarrierPanelController extends Controller
{
    public function __construct(private DeliveryDispatch $dispatch, private CountryContext $countryContext) {}

    public function dashboard(Request $request, Scorecards $scorecards): View
    {
        $carrier = $this->resolve($request);

        if ($carrier === null) {
            return app(PanelDashboardController::class)($request);
        }

        $shipments = Shipment::query()->where('carrier_id', $carrier->id);
        $agentUserIds = $this->agentUserIds($carrier);

        return view('panels.carrier.dashboard', [
            'carrier' => $carrier,
            'pipeline' => (clone $shipments)->selectRaw('status, count(*) as total, min(updated_at) as oldest')->groupBy('status')->get()->keyBy(fn ($row) => $row->status instanceof ShipmentStatus ? $row->status->value : $row->status),
            'shipmentsToday' => (clone $shipments)->where('created_at', '>=', now($this->countryContext->current()->timezone)->startOfDay()->utc())->count(),
            'unassigned' => $this->pickupQuery($carrier)->count(),
            'agentsActive' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->where('status', AgentStatus::Active)->count(),
            'agentsTotal' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->count(),
            'codInHand' => (string) CodCollection::query()->whereIn('agent_user_id', $agentUserIds)->whereNull('cod_settlement_id')->sum('amount'),
            'openClaims' => CarrierClaim::query()->where('carrier_id', $carrier->id)->whereIn('status', [ClaimStatus::Submitted, ClaimStatus::UnderReview])->count(),
            'score' => $this->scoreFor($carrier, $scorecards),
            // QC-062 (Figma 106:15992): SLA by zone, last 30 days.
            'slaByZone' => app(CarrierBoard::class)->slaByZone($carrier),
        ]);
    }

    public function dispatchBoard(Request $request): View
    {
        $carrier = $this->carrier($request);

        return view('panels.carrier.dispatch', [
            'agents' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->whereIn('status', [AgentStatus::Active, AgentStatus::Unavailable])
                ->withCount(['assignments as open_count' => fn ($query) => $query->whereIn('status', AssignmentStatus::open())])
                ->withCount(['assignments as failed_today' => fn ($query) => $query->where('status', AssignmentStatus::Failed)->where('updated_at', '>=', now()->startOfDay())])
                ->orderBy('name')->get(),
            'codByAgent' => CodCollection::query()->whereIn('agent_user_id', $this->agentUserIds($carrier))->whereNull('cod_settlement_id')
                ->selectRaw('agent_user_id, sum(amount) as total')->groupBy('agent_user_id')->pluck('total', 'agent_user_id'),
            'open' => DeliveryAssignment::query()->where('carrier_id', $carrier->id)->whereIn('status', AssignmentStatus::open())->with(['agent', 'subOrder.order'])->orderBy('sla_due_at')->limit(50)->get(),
            'exceptions' => Shipment::query()->where('carrier_id', $carrier->id)->where('status', ShipmentStatus::Failed)->count(),
            // QC-062 (Figma 90:3664): "Unassigned & exceptions" — parcels waiting for a driver and failed / returning ones.
            'attention' => $this->pickupQuery($carrier)->with('subOrder.order')->orderBy('updated_at')->limit(25)->get()
                ->concat(app(CarrierBoard::class)->exceptions($carrier)->with('subOrder.order')->orderBy('updated_at')->limit(25)->get()),
        ]);
    }

    public function pickups(Request $request): View
    {
        $carrier = $this->carrier($request);

        return view('panels.carrier.pickups', [
            'shipments' => $this->pickupQuery($carrier)->with('subOrder.order', 'subOrder.vendor')->orderBy('id')->paginate(30),
            'agents' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->where('status', AgentStatus::Active)->orderBy('name')->get(),
        ]);
    }

    public function assign(Request $request, int $shipment): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $shipment = Shipment::query()->where('carrier_id', $carrier->id)->findOrFail($shipment);
        $data = $request->validate(['agent_id' => ['required_without:auto', 'nullable', 'integer'], 'auto' => ['nullable', 'boolean']]);
        $subOrder = $shipment->subOrder()->firstOrFail();

        $assignment = ($data['auto'] ?? false)
            ? $this->dispatch->autoAssign($subOrder, ActorType::Carrier, $request->user()->id, $carrier)
            : $this->dispatch->assign($subOrder, DeliveryAgent::query()->where('carrier_id', $carrier->id)->findOrFail($data['agent_id']), ActorType::Carrier, $request->user()->id, $carrier);

        return back()->with('status', __(':number assigned to :agent.', ['number' => $shipment->number, 'agent' => $assignment->agent->name]));
    }

    public function shipments(Request $request): View
    {
        $carrier = $this->carrier($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ShipmentStatus::class)]]);

        return view('panels.carrier.shipments', [
            'shipments' => Shipment::query()->where('carrier_id', $carrier->id)
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->with(['subOrder.order', 'assignments' => fn ($query) => $query->reorder('id', 'desc')->with('agent')])->latest('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'statuses' => ShipmentStatus::cases(),
        ]);
    }

    public function assignments(Request $request): View
    {
        $carrier = $this->carrier($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AssignmentStatus::class)]]);

        return view('panels.carrier.assignments', [
            'assignments' => DeliveryAssignment::query()->where('carrier_id', $carrier->id)
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->with(['agent', 'subOrder.order'])->latest('id')->paginate(30)->withQueryString(),
            'agents' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->where('status', AgentStatus::Active)->orderBy('name')->pluck('name', 'id'),
            'filters' => $filters,
            'statuses' => AssignmentStatus::cases(),
        ]);
    }

    public function reassign(Request $request, int $assignment): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:255']]);
        $assignment = DeliveryAssignment::query()->where('carrier_id', $carrier->id)->findOrFail($assignment);
        $agent = DeliveryAgent::query()->where('carrier_id', $carrier->id)->findOrFail($data['agent_id']);
        $this->dispatch->reassign($assignment, $agent, ActorType::Carrier, $request->user()->id, $carrier, $data['reason'] ?? null);

        return back()->with('status', __('Reassigned with a new delivery code.'));
    }

    public function exceptions(Request $request, DeliverySettings $settings): View
    {
        $carrier = $this->carrier($request);

        return view('panels.carrier.exceptions', [
            'shipments' => app(CarrierBoard::class)->exceptions($carrier)
                ->with(['subOrder.order', 'assignments' => fn ($query) => $query->reorder('id', 'desc')->with('agent')])->orderBy('updated_at')->get(),
            'maxAttempts' => $settings->maxAttempts(),
        ]);
    }

    public function shipmentAction(Request $request, int $shipment, string $action): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $shipment = Shipment::query()->where('carrier_id', $carrier->id)->findOrFail($shipment);
        $userId = $request->user()->id;

        match ($action) {
            'reschedule' => $this->dispatch->reschedule($shipment, $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'], ActorType::Carrier, $userId, $carrier),
            'return' => $this->dispatch->returnToOrigin($shipment, ActorType::Carrier, $userId, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $carrier),
            'returned' => $this->dispatch->confirmReturned($shipment, ActorType::Carrier, $userId, $carrier),
        };

        return back()->with('status', __('Saved.'));
    }

    public function agents(Request $request): View
    {
        $carrier = $this->carrier($request);

        return view('panels.carrier.agents', [
            'agents' => DeliveryAgent::query()->where('carrier_id', $carrier->id)
                ->withCount(['assignments as delivered_count' => fn ($query) => $query->where('status', AssignmentStatus::Delivered)])
                ->orderBy('name')->get(),
            'supervisors' => User::query()->whereIn('id', $this->supervisorIds($carrier))->orderBy('name')->get(),
        ]);
    }

    /**
     * QA-003: an invitation the driver accepts by email; nothing is attached
     * to an account (and no stored name is shown) before that.
     */
    public function storeAgent(Request $request, TeamInvitations $invitations): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:24', new CountryPhone],
            'vehicle_type' => ['nullable', Rule::in(['bike', 'car', 'van', 'truck'])],
            'vehicle_plate' => ['nullable', 'string', 'max:24'],
        ]);
        $data['phone'] = isset($data['phone']) ? CountryPhone::normalize($data['phone']) : null;
        $invitations->invite('carrier', Carrier::TENANT, $carrier->id, $carrier->name, MembershipRole::DeliveryAgent, null, $data['email'], $data['name'],
            array_filter(['phone' => $data['phone'], 'vehicle_type' => $data['vehicle_type'] ?? null, 'vehicle_plate' => $data['vehicle_plate'] ?? null]), $request->user()->id);

        return back()->with('status', __('We emailed an invitation to :email. The driver appears here once they accept; Nawy checks their documents before the first delivery.', ['email' => Str::lower($data['email'])]));
    }

    public function agentStatus(Request $request, int $agent, DeliveryAgents $agents): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $agent = DeliveryAgent::query()->where('carrier_id', $carrier->id)->findOrFail($agent);
        $data = $request->validate(['status' => ['required', Rule::in([AgentStatus::Suspended->value, AgentStatus::Active->value])], 'reason' => ['nullable', 'string', 'max:255']]);

        if ($data['status'] === AgentStatus::Active->value && $agent->status === AgentStatus::PendingDocuments) {
            throw new TransitionRefused(__('Nawy activates new agents after checking their documents.'));
        }

        $agents->move($agent, AgentStatus::from($data['status']), ActorType::Carrier, $request->user()->id, $data['reason'] ?? null);

        return back()->with('status', __('Agent updated.'));
    }

    /**
     * Owner only (SRS §4.7): another supervisor of the company.
     */
    public function storeSupervisor(Request $request, TeamInvitations $invitations): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255']]);
        $invitations->invite('carrier', Carrier::TENANT, $carrier->id, $carrier->name, MembershipRole::CarrierSupervisor, null, $data['email'], $data['name'], [], $request->user()->id);

        return back()->with('status', __('We emailed an invitation to :email. They can sign in to the Shipping panel once they accept.', ['email' => Str::lower($data['email'])]));
    }

    public function cod(Request $request): View
    {
        $carrier = $this->carrier($request);
        $agents = DeliveryAgent::query()->where('carrier_id', $carrier->id)->orderBy('name')->get();
        $userIds = $agents->pluck('user_id');
        $inHand = CodCollection::query()->whereIn('agent_user_id', $userIds)->whereNull('cod_settlement_id')
            ->selectRaw('agent_user_id, count(*) as stops, sum(amount) as total')->groupBy('agent_user_id')->get()->keyBy('agent_user_id');
        $settlements = CodSettlement::query()->whereIn('agent_user_id', $userIds)->latest('business_date')->limit(60)->get();

        return view('panels.carrier.cod', [
            'agents' => $agents,
            'inHand' => $inHand,
            'settlements' => $settlements,
            'collectedToday' => (string) CodCollection::query()->whereIn('agent_user_id', $userIds)->where('collected_at', '>=', now($this->countryContext->current()->timezone)->startOfDay()->utc())->sum('amount'),
            'cashInHand' => (string) $inHand->sum('total'),
            'remitted' => (string) $settlements->sum('remitted_amount'),
            'disputed' => $settlements->filter(fn (CodSettlement $settlement): bool => ($settlement->status->value ?? $settlement->status) === 'disputed')->count(),
        ]);
    }

    public function claims(Request $request, Scorecards $scorecards): View
    {
        $carrier = $this->carrier($request);

        return view('panels.carrier.claims', [
            'claims' => CarrierClaim::query()->where('carrier_id', $carrier->id)->with('subOrder')->latest('id')->paginate(25),
            'score' => $this->scoreFor($carrier, $scorecards),
            'openClaims' => CarrierClaim::query()->where('carrier_id', $carrier->id)->whereIn('status', [ClaimStatus::Submitted, ClaimStatus::UnderReview])->count(),
            'paid' => (string) CarrierClaim::query()->where('carrier_id', $carrier->id)->where('status', ClaimStatus::Compensated)->where('compensated_at', '>=', now()->subDays(30))->sum('approved_amount'),
        ]);
    }

    /**
     * The company's side of a claim (evidence, explanation) while Nawy decides.
     */
    public function respondClaim(Request $request, int $claim): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $claim = CarrierClaim::query()->where('carrier_id', $carrier->id)->findOrFail($claim);
        $data = $request->validate(['response' => ['required', 'string', 'min:5', 'max:1000']]);

        if (! in_array($claim->status, [ClaimStatus::Submitted, ClaimStatus::UnderReview], true)) {
            throw new TransitionRefused(__('This claim is already decided.'));
        }

        $claim->forceFill(['carrier_response' => $data['response'], 'carrier_responded_at' => now()])->save();

        return back()->with('status', __('Response sent to Nawy.'));
    }

    public function company(Request $request): View
    {
        return view('panels.carrier.company', ['carrier' => $this->carrier($request)]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $carrier = $this->carrier($request);
        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:24', new CountryPhone],
            'coverage' => ['nullable', 'string', 'max:500'],
            'fleet_size' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $carrier->forceFill([
            'contact_name' => $data['contact_name'],
            'contact_email' => Str::lower($data['contact_email']),
            'contact_phone' => CountryPhone::normalize($data['contact_phone']),
            'details' => array_merge($carrier->details ?? [], [
                'coverage' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['coverage'] ?? ''))))),
                'fleet_size' => $data['fleet_size'] ?? null,
            ]),
        ])->save();

        return back()->with('status', __('Company details saved.'));
    }

    private function carrier(Request $request): Carrier
    {
        return $request->attributes->get('carrier');
    }

    /**
     * The dashboard resolves the company itself so that a supervisor
     * without one still reaches the generic landing page.
     */
    private function resolve(Request $request): ?Carrier
    {
        try {
            app(ResolveCarrier::class)->handle($request, fn () => response(''));
        } catch (HttpException) {
            return null;
        }

        return $request->attributes->get('carrier');
    }

    /**
     * Handed-over parcels without an agent yet.
     *
     * @return Builder<Shipment>
     */
    private function pickupQuery(Carrier $carrier): Builder
    {
        return app(CarrierBoard::class)->pickups($carrier);
    }

    /**
     * @return Collection<int, int>
     */
    private function agentUserIds(Carrier $carrier): Collection
    {
        return app(CarrierBoard::class)->agentUserIds($carrier);
    }

    /**
     * @return array<int, int>
     */
    private function supervisorIds(Carrier $carrier): array
    {
        return RoleMembership::query()->where('role', MembershipRole::CarrierSupervisor)
            ->where('tenant_type', Carrier::TENANT)->where('tenant_id', $carrier->id)
            ->where('country_id', $this->countryContext->current()->id)->where('status', 'active')->pluck('user_id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreFor(Carrier $carrier, Scorecards $scorecards): array
    {
        return $scorecards->carriers(now()->subDays(30), now())->first(fn (array $row): bool => $row['carrier']->id === $carrier->id) ?? [];
    }
}
