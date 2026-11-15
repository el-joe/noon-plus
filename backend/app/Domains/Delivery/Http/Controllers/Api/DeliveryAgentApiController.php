<?php

namespace App\Domains\Delivery\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Http\Resources\AgentAssignmentResource;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\AgentEarnings;
use App\Domains\Delivery\Support\AgentWorkspace;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentRequirement;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Support\TokenScopes;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Delivery app API (Flutter, Sanctum; SRS §4.6, ADR 15, role-permission
 * matrix §9): sign-in, my profile and availability, my assignments, and
 * the delivery steps — accept, decline, start, arrive, proof, confirm
 * ("Codes match") and fail. Only the agent's own assignments are reachable.
 */
class DeliveryAgentApiController extends Controller
{
    public function __construct(private AgentWorkspace $workspace, private AgentAssignments $assignments) {}

    public function login(Request $request, RecordLoginEvent $recordLoginEvent): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);
        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password) || $user->status !== 'active') {
            $recordLoginEvent->handle($request, 'delivery_app', LoginOutcome::WrongCredentials, email: $data['email']);

            return ApiResponse::error('invalid_credentials', __('The email or password is not right.'), 422);
        }

        $agent = $this->workspace->agentFor($user);

        if ($agent === null) {
            $recordLoginEvent->handle($request, 'delivery_app', LoginOutcome::NoPanelAccess, $user);

            return ApiResponse::error('not_a_delivery_agent', __('This account is not an active delivery agent in this country.'), 403);
        }

        $recordLoginEvent->handle($request, 'delivery_app', LoginOutcome::Succeeded, $user);

        return ApiResponse::success([
            'token' => $user->createToken($data['device_name'], [TokenScopes::DELIVERY], app(TokenScopes::class)->expiresAt(TokenScopes::DELIVERY))->plainTextToken,
            'agent' => $this->profile($agent),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profile($this->agent($request)));
    }

    public function availability(Request $request, DeliveryAgents $agents): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $agent = $agents->setAvailable($this->agent($request), (bool) $data['available'], $request->user()->id);

        return ApiResponse::success($this->profile($agent));
    }

    public function location(Request $request, DeliveryAgents $agents): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $agents->ping($this->agent($request), (float) $data['lat'], (float) $data['lng']);

        return ApiResponse::success(['saved' => true]);
    }

    /**
     * Same input as the delivery panel profile: `phone` (required, country
     * phone), `vehicle_type` (bike | car | van | truck, nullable), `vehicle_plate`.
     */
    public function update(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24', new CountryPhone],
            'vehicle_type' => ['nullable', Rule::in(['bike', 'car', 'van', 'truck'])],
            'vehicle_plate' => ['nullable', 'string', 'max:24'],
        ]);
        $agent->forceFill(['phone' => CountryPhone::normalize($data['phone'])] + array_intersect_key($data, array_flip(['vehicle_type', 'vehicle_plate'])))->save();

        return ApiResponse::success($this->profile($agent->refresh()));
    }

    /**
     * Last 7 days at the country's pay rates ({@see AgentEarnings}, same as the panel).
     */
    public function earnings(Request $request, AgentEarnings $earnings): JsonResponse
    {
        $summary = $earnings->lastSevenDays($this->agent($request));
        $money = fn (mixed $amount): ?array => $amount === null ? null : MoneyResource::of((string) $amount, $summary['currency']);

        return ApiResponse::success([
            'days' => $summary['days']->map(fn (array $day): array => [
                'date' => $day['day']->toDateString(),
                'delivered' => $day['delivered'],
                'failed' => $day['failed'],
                'earned' => $money($day['earned']),
            ])->values()->all(),
            'total' => $money($summary['total']),
            'stops' => (int) $summary['stops'],
            'failed' => (int) $summary['failed'],
            'per_stop' => $money($summary['per_stop']),
            'per_failed' => $money($summary['per_failed']),
        ]);
    }

    /**
     * Cash in hand (collections not settled yet) and the last 30 settlements.
     */
    public function cod(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $collections = CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->latest('id')->get();
        $orders = Order::query()->whereIn('id', $collections->pluck('order_id'))->pluck('number', 'id');
        $subOrders = SubOrder::query()->whereIn('id', $collections->pluck('sub_order_id'))->pluck('number', 'id');

        return ApiResponse::success([
            'in_hand' => MoneyResource::make((string) $collections->sum('amount')),
            'collections' => $collections->map(fn (CodCollection $collection): array => [
                'id' => $collection->id,
                'order_number' => $orders[$collection->order_id] ?? null,
                'sub_order_number' => $subOrders[$collection->sub_order_id] ?? null,
                'amount' => MoneyResource::of((string) $collection->amount, $collection->currency),
                'collected_at' => $collection->collected_at?->toIso8601String(),
            ])->all(),
            'settlements' => CodSettlement::query()->where('agent_user_id', $agent->user_id)->latest('business_date')->limit(30)->get()
                ->map(fn (CodSettlement $settlement): array => [
                    'id' => $settlement->id,
                    'number' => $settlement->number,
                    'business_date' => $settlement->business_date?->toDateString(),
                    'expected' => MoneyResource::of((string) $settlement->expected_amount, $settlement->currency),
                    'remitted' => MoneyResource::of($settlement->remitted_amount === null ? null : (string) $settlement->remitted_amount, $settlement->currency),
                    'status' => $settlement->status->value,
                ])->all(),
        ]);
    }

    public function documents(Request $request, Documents $documents): JsonResponse
    {
        $agent = $this->agent($request);

        return ApiResponse::success([
            'requirements' => $documents->requirements(DocumentOwner::DeliveryAgent)->map(fn (DocumentType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name(),
                'mandatory' => $type->requirement === DocumentRequirement::Mandatory,
            ])->values()->all(),
            'uploads' => Document::query()->where('owner_type', DocumentOwner::DeliveryAgent->value)->where('owner_id', $agent->id)->latest('id')->get()
                ->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'document_type_id' => $document->document_type_id,
                    'type' => (string) ($document->type()?->name() ?? ''),
                    'status' => $document->status->value,
                    'valid_until' => $document->valid_until?->toDateString(),
                    'uploaded_at' => $document->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'done', ...array_map(fn (AssignmentStatus $status): string => $status->value, AssignmentStatus::cases())])]]);
        $status = $filters['status'] ?? 'open';

        $assignments = DeliveryAssignment::query()
            ->where('delivery_agent_id', $this->agent($request)->id)
            ->when($status === 'open', fn ($query) => $query->whereIn('status', AssignmentStatus::open()))
            ->when($status === 'done', fn ($query) => $query->whereIn('status', [AssignmentStatus::Delivered, AssignmentStatus::Failed]))
            ->when(! in_array($status, ['open', 'done'], true), fn ($query) => $query->where('status', $status))
            ->with(['subOrder.order', 'subOrder.vendor', 'subOrder.items'])
            ->latest('id')
            ->paginate(30);

        return ApiResponse::success(AgentAssignmentResource::collection($assignments->getCollection())->resolve(), [
            'current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage(), 'total' => $assignments->total(),
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return $this->present($this->assignment($request, $number));
    }

    public function accept(Request $request, string $number): JsonResponse
    {
        return $this->present($this->assignments->accept($this->assignment($request, $number), $request->user()->id));
    }

    public function decline(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->present($this->assignments->decline($this->assignment($request, $number), $request->user()->id, $data['reason']));
    }

    public function start(Request $request, string $number): JsonResponse
    {
        return $this->present($this->assignments->start($this->assignment($request, $number), $request->user()->id));
    }

    public function arrive(Request $request, string $number): JsonResponse
    {
        return $this->present($this->assignments->arrive($this->assignment($request, $number)));
    }

    public function proof(Request $request, string $number, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate($this->proofRules($settings) + [
            'photo' => ['required_without_all:signature,signature_data', 'nullable', 'image', 'max:'.$settings->proofMaxKilobytes()],
        ]);

        return $this->present($this->assignments->attachProof($this->assignment($request, $number), $request->file('photo'), $request->file('signature'), $data['signature_data'] ?? null));
    }

    /**
     * "Codes match" — the agent confirms; never sends the code itself.
     */
    public function confirm(Request $request, string $number, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate(['codes_match' => ['required', 'accepted']] + $this->proofRules($settings) + [
            'photo' => ['nullable', 'image', 'max:'.$settings->proofMaxKilobytes()],
        ]);
        $assignment = $this->assignment($request, $number);

        if ($request->hasFile('photo') || $request->hasFile('signature') || isset($data['signature_data'])) {
            $this->assignments->attachProof($assignment, $request->file('photo'), $request->file('signature'), $data['signature_data'] ?? null);
        }

        return $this->present($this->assignments->confirmDelivery($assignment, $request->user()->id, true));
    }

    public function fail(Request $request, string $number, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in($settings->failureReasons())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->present($this->assignments->fail($this->assignment($request, $number), $request->user()->id, $data['reason'], $data['note'] ?? null));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function proofRules(DeliverySettings $settings): array
    {
        return [
            'signature' => ['nullable', 'image', 'max:'.$settings->proofMaxKilobytes()],
            'signature_data' => ['nullable', 'string', 'max:'.($settings->proofMaxKilobytes() * 1400)],
        ];
    }

    private function agent(Request $request): DeliveryAgent
    {
        return $request->attributes->get('delivery_agent');
    }

    private function assignment(Request $request, string $number): DeliveryAssignment
    {
        return $this->workspace->assignment($this->agent($request), $number);
    }

    private function present(DeliveryAssignment $assignment): JsonResponse
    {
        return ApiResponse::success(AgentAssignmentResource::make($assignment->refresh()->load(['subOrder.order', 'subOrder.vendor', 'subOrder.items']))->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(DeliveryAgent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'email' => $agent->user()->email,
            'phone' => $agent->phone,
            'status' => $agent->status->value,
            'available' => $agent->status === AgentStatus::Active,
            'carrier' => $agent->carrier?->name,
            'vehicle' => ['type' => $agent->vehicle_type, 'plate' => $agent->vehicle_plate],
            'cod_in_hand' => (string) CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->sum('amount'),
        ];
    }
}
