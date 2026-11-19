<?php

namespace App\Domains\Delivery\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\CarrierBoard;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Panels\Support\TeamInvitations;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Supervisor ops app (Sanctum `ops:supervisor`): the Shipping company's
 * dashboard, dispatch board (assign / auto-assign), failed deliveries
 * (reschedule, return to origin, back at origin) and drivers, through
 * {@see DeliveryDispatch} and {@see DeliveryAgents} with the carrier as actor
 * and acting company, like CarrierPanelController. Another company's
 * shipment or agent is a 404.
 */
class CarrierOpsController extends Controller
{
    use RefusesTransitions;

    public function __construct(private DeliveryDispatch $dispatch, private CarrierBoard $board, private CountryContext $countryContext) {}

    public function dashboard(Request $request): JsonResponse
    {
        $carrier = $this->carrier($request);
        $today = CarbonImmutable::now($this->countryContext->current()->timezone)->startOfDay()->utc();
        $assignments = fn () => DeliveryAssignment::query()->where('carrier_id', $carrier->id);

        return ApiResponse::success([
            'company' => ['id' => $carrier->id, 'name' => $carrier->name],
            'kpis' => [
                'open_assignments' => $assignments()->whereIn('status', AssignmentStatus::open())->count(),
                'unassigned' => $this->board->pickups($carrier)->count(),
                'delivered_today' => $assignments()->where('status', AssignmentStatus::Delivered)->where('delivered_at', '>=', $today)->count(),
                'failed_today' => $assignments()->where('status', AssignmentStatus::Failed)->where('failed_at', '>=', $today)->count(),
                'drivers_active' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->where('status', AgentStatus::Active)->count(),
                'cod_in_hand' => MoneyResource::make((string) CodCollection::query()->whereIn('agent_user_id', $this->board->agentUserIds($carrier))->whereNull('cod_settlement_id')->sum('amount')),
            ],
        ]);
    }

    public function dispatchBoard(Request $request): JsonResponse
    {
        $carrier = $this->carrier($request);

        return ApiResponse::success([
            'unassigned' => $this->board->pickups($carrier)->with('subOrder.order')->orderBy('id')->limit(50)->get()
                ->map(fn (Shipment $shipment): array => [
                    'id' => $shipment->id,
                    'number' => $shipment->number,
                    'sub_order_number' => $shipment->subOrder?->number,
                    'status' => $shipment->status->value,
                    'city' => $this->city($shipment->subOrder),
                    'cod_amount' => $shipment->subOrder === null ? null : MoneyResource::of($this->dispatch->expectedCod($shipment->subOrder), $shipment->subOrder->order?->currency),
                ])->all(),
            'open' => DeliveryAssignment::query()->where('carrier_id', $carrier->id)->whereIn('status', AssignmentStatus::open())
                ->with(['agent', 'subOrder.order'])->orderBy('sla_due_at')->limit(50)->get()
                ->map(fn (DeliveryAssignment $assignment): array => $this->assignmentRow($assignment) + [
                    'sla_due_at' => $assignment->sla_due_at?->toIso8601String(),
                    'city' => $this->city($assignment->subOrder),
                ])->all(),
            'drivers' => DeliveryAgent::query()->where('carrier_id', $carrier->id)->whereIn('status', [AgentStatus::Active, AgentStatus::Unavailable])
                ->withCount(['assignments as open_count' => fn ($query) => $query->whereIn('status', AssignmentStatus::open())])
                ->orderBy('name')->get()
                ->map(fn (DeliveryAgent $agent): array => ['id' => $agent->id, 'name' => $agent->name, 'status' => $agent->status->value, 'open' => (int) $agent->open_count])->all(),
        ]);
    }

    /**
     * `agent_id` = one of the company's agents; null or missing = automatic assignment.
     */
    public function assign(Request $request, int $shipment): JsonResponse
    {
        $carrier = $this->carrier($request);
        $shipment = Shipment::query()->where('carrier_id', $carrier->id)->findOrFail($shipment);
        $agentId = $request->validate(['agent_id' => ['nullable', 'integer']])['agent_id'] ?? null;
        $agent = $agentId === null ? null : DeliveryAgent::query()->where('carrier_id', $carrier->id)->findOrFail($agentId);
        $subOrder = $shipment->subOrder()->firstOrFail();

        return $this->refusable(function () use ($request, $carrier, $agent, $subOrder): JsonResponse {
            $assignment = $agent === null
                ? $this->dispatch->autoAssign($subOrder, ActorType::Carrier, $request->user()->id, $carrier)
                : $this->dispatch->assign($subOrder, $agent, ActorType::Carrier, $request->user()->id, $carrier);

            return ApiResponse::success(['assignment' => $this->assignmentRow($assignment->load('agent'))]);
        });
    }

    public function failed(Request $request, DeliverySettings $settings): JsonResponse
    {
        $maxAttempts = $settings->maxAttempts();

        return ApiResponse::success($this->board->exceptions($this->carrier($request))
            ->with(['subOrder.order', 'assignments' => fn ($query) => $query->reorder('id', 'desc')])->orderBy('updated_at')->get()
            ->map(fn (Shipment $shipment): array => $this->failedRow($shipment, $maxAttempts))->all());
    }

    /**
     * Same actions as the Shipping panel: `reschedule` {date: Y-m-d},
     * `return` {reason} (return to origin) and `returned` (back at origin).
     */
    public function failedAction(Request $request, int $shipment, string $action, DeliverySettings $settings): JsonResponse
    {
        $carrier = $this->carrier($request);
        $shipment = Shipment::query()->where('carrier_id', $carrier->id)->findOrFail($shipment);
        $userId = $request->user()->id;
        $input = match ($action) {
            'reschedule' => $request->validate(['date' => ['required', 'date_format:Y-m-d']]),
            'return' => $request->validate(['reason' => ['required', 'string', 'max:255']]),
            default => [],
        };

        return $this->refusable(function () use ($shipment, $action, $input, $userId, $carrier, $settings): JsonResponse {
            match ($action) {
                'reschedule' => $this->dispatch->reschedule($shipment, $input['date'], ActorType::Carrier, $userId, $carrier),
                'return' => $this->dispatch->returnToOrigin($shipment, ActorType::Carrier, $userId, $input['reason'], $carrier),
                'returned' => $this->dispatch->confirmReturned($shipment, ActorType::Carrier, $userId, $carrier),
            };

            $shipment = $shipment->refresh()->load(['subOrder.order', 'assignments' => fn ($query) => $query->reorder('id', 'desc')]);

            return ApiResponse::success($this->failedRow($shipment, $settings->maxAttempts()));
        });
    }

    public function drivers(Request $request): JsonResponse
    {
        $carrier = $this->carrier($request);
        $agents = DeliveryAgent::query()->where('carrier_id', $carrier->id)
            ->withCount(['assignments as open_count' => fn ($query) => $query->whereIn('status', AssignmentStatus::open())])
            ->orderBy('name')->get();
        $cod = CodCollection::query()->whereIn('agent_user_id', $agents->pluck('user_id'))->whereNull('cod_settlement_id')
            ->selectRaw('agent_user_id, sum(amount) as total')->groupBy('agent_user_id')->pluck('total', 'agent_user_id');

        return ApiResponse::success($agents->map(fn (DeliveryAgent $agent): array => $this->driverRow($agent, (string) ($cod[$agent->user_id] ?? '0')))->all());
    }

    /**
     * Add a driver the same way as the Shipping panel (QA-003): a team
     * invitation the driver accepts by email. Nothing is attached and no
     * stored name is revealed before that, so the answer is only `invited`.
     */
    public function inviteDriver(Request $request, TeamInvitations $invitations): JsonResponse
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

        return ApiResponse::success([
            'invited' => true,
            'email' => Str::lower($data['email']),
            'message' => __('We emailed an invitation to :email. The driver appears here once they accept; Nawy checks their documents before the first delivery.', ['email' => Str::lower($data['email'])]),
        ], status: 202);
    }

    /**
     * Same rules as the Shipping panel: `status` ∈ suspended | active,
     * optional `reason`; a new agent (pending documents) is activated by Nawy only.
     */
    public function driverStatus(Request $request, int $agent, DeliveryAgents $agents): JsonResponse
    {
        $carrier = $this->carrier($request);
        $agent = DeliveryAgent::query()->where('carrier_id', $carrier->id)->findOrFail($agent);
        $data = $request->validate(['status' => ['required', Rule::in([AgentStatus::Suspended->value, AgentStatus::Active->value])], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->refusable(function () use ($request, $agent, $agents, $data): JsonResponse {
            if ($data['status'] === AgentStatus::Active->value && $agent->status === AgentStatus::PendingDocuments) {
                throw new TransitionRefused(__('Nawy activates new agents after checking their documents.'));
            }

            $agents->move($agent, AgentStatus::from($data['status']), ActorType::Carrier, $request->user()->id, $data['reason'] ?? null);
            $agent = DeliveryAgent::query()->withCount(['assignments as open_count' => fn ($query) => $query->whereIn('status', AssignmentStatus::open())])->findOrFail($agent->id);

            return ApiResponse::success($this->driverRow($agent, (string) CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->sum('amount')));
        });
    }

    private function carrier(Request $request): Carrier
    {
        return $request->attributes->get('ops_workspace');
    }

    private function city(?SubOrder $subOrder): ?string
    {
        return $subOrder?->order?->shipping_address['city'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(DeliveryAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'number' => $assignment->number,
            'status' => $assignment->status->value,
            'agent' => $assignment->agent === null ? null : ['id' => $assignment->agent->id, 'name' => $assignment->agent->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedRow(Shipment $shipment, int $maxAttempts): array
    {
        $lastFailed = $shipment->assignments->first(fn (DeliveryAssignment $assignment): bool => $assignment->failed_at !== null);

        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'sub_order_number' => $shipment->subOrder?->number,
            'status' => $shipment->status->value,
            'failure_reason' => $shipment->last_failure_reason,
            'attempts' => (int) $shipment->attempts,
            'max_attempts' => $maxAttempts,
            'city' => $this->city($shipment->subOrder),
            'rescheduled_for' => $shipment->rescheduled_for?->toDateString(),
            'last_failed_at' => $lastFailed?->failed_at?->toIso8601String(),
            'actions' => $this->board->exceptionActions($shipment, $maxAttempts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverRow(DeliveryAgent $agent, string $codInHand): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'status' => $agent->status->value,
            'phone' => $agent->phone,
            'vehicle' => ['type' => $agent->vehicle_type, 'plate' => $agent->vehicle_plate],
            'open_assignments' => (int) $agent->open_count,
            'cod_in_hand' => MoneyResource::make($codInHand),
            'last_ping_at' => $agent->last_seen_at?->toIso8601String(),
        ];
    }
}
