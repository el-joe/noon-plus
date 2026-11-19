<?php

namespace App\Domains\Delivery\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Http\AgentPayPresenter;
use App\Domains\Delivery\Http\Resources\AgentAssignmentResource;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\AgentEarnings;
use App\Domains\Delivery\Support\AgentPay;
use App\Domains\Delivery\Support\AgentWorkspace;
use App\Domains\Delivery\Support\DeliveryAgents;
use App\Domains\Delivery\Support\DeliverySettings;
use App\Domains\Delivery\Support\ParcelBarcode;
use App\Domains\Delivery\Support\PayslipDocument;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Support\ApiTwoFactor;
use App\Domains\Identity\Support\TokenScopes;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\MoneyFactory;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivery app API (Flutter, Sanctum; SRS §4.6, ADR 15, role-permission
 * matrix §9): sign-in, my profile and availability, my assignments, and
 * the delivery steps — accept, decline, start, arrive, proof, confirm
 * ("Codes match") and fail. Only the agent's own assignments are reachable.
 */
class DeliveryAgentApiController extends Controller
{
    use RefusesTransitions;

    public function __construct(private AgentWorkspace $workspace, private AgentAssignments $assignments) {}

    public function login(Request $request, RecordLoginEvent $recordLoginEvent): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'two_factor_code' => ['nullable', 'string', 'max:12'],
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

        if (($stop = app(ApiTwoFactor::class)->check($user, $data['two_factor_code'] ?? null)) !== null) {
            $recordLoginEvent->handle($request, 'delivery_app', $stop->status() === 202 ? LoginOutcome::SecondStepSent : LoginOutcome::WrongCode, $user);

            return $stop;
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
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // Sent by the app's background tracker; validated, the ping keeps the position.
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ]);
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

    /**
     * Required documents (with `has_expiry`, `max_mb`), the agent's uploads
     * (newest first, with expiry warnings) and the mandatory types still missing.
     */
    public function documents(Request $request, Documents $documents): JsonResponse
    {
        $agent = $this->agent($request);

        return ApiResponse::success([
            'requirements' => $documents->requirements(DocumentOwner::DeliveryAgent)->map(fn (DocumentType $type): array => AgentPayPresenter::requirement($type))->values()->all(),
            'uploads' => Document::query()->where('owner_type', DocumentOwner::DeliveryAgent->value)->where('owner_id', $agent->id)->latest('id')->get()
                ->map(fn (Document $document): array => AgentPayPresenter::document($document))->all(),
            'missing' => $documents->missingMandatory(DocumentOwner::DeliveryAgent, DocumentOwner::DeliveryAgent->value, $agent->id)->pluck('id')->values()->all(),
        ]);
    }

    /**
     * Upload (or re-upload) a document: multipart `document_type_id`, `file`
     * (pdf / jpg / jpeg / png / webp, at most the type's `max_mb`) and
     * `valid_until` (required when the type has an expiry). Reuses the
     * Documents domain: pending until Nawy reviews it; a rejected or
     * expired one goes back to pending.
     */
    public function storeDocument(Request $request, Documents $documents): JsonResponse
    {
        $agent = $this->agent($request);
        $type = $documents->requirements(DocumentOwner::DeliveryAgent)->firstWhere('id', $request->integer('document_type_id'));
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($type): void {
                if ($type === null) {
                    $fail(__('This document is not needed.'));
                }
            }],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.(1024 * min(20, (int) ($type?->max_mb ?: 10)))],
            'valid_until' => [$type?->has_expiry ? 'required' : 'nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        return $this->refusable(fn (): JsonResponse => ApiResponse::success(AgentPayPresenter::document(
            $documents->upload($type, DocumentOwner::DeliveryAgent->value, $agent->id, $request->file('file'), $request->user()->id, null, $data['valid_until'] ?? null),
        ), status: 201));
    }

    /**
     * The agent's own uploaded file (private disk).
     */
    public function documentFile(Request $request, int $document): StreamedResponse
    {
        $document = Document::query()->where('owner_type', DocumentOwner::DeliveryAgent->value)->where('owner_id', $this->agent($request)->id)->findOrFail($document);

        return Storage::disk(Documents::DISK)->download($document->file_path, $document->original_name);
    }

    public function payslips(Request $request): JsonResponse
    {
        $payslips = DeliveryPayslip::query()->where('delivery_agent_id', $this->agent($request)->id)->latest('period_end')->latest('id')->paginate(30);

        return ApiResponse::success(collect($payslips->items())->map(fn (DeliveryPayslip $payslip): array => AgentPayPresenter::payslip($payslip))->all(), [
            'current_page' => $payslips->currentPage(), 'last_page' => $payslips->lastPage(), 'total' => $payslips->total(),
        ]);
    }

    public function payslip(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success(AgentPayPresenter::payslip($this->ownPayslip($request, $number)->load('lines.assignment.subOrder'), true));
    }

    public function payslipPdf(Request $request, string $number, PayslipDocument $document): Response
    {
        return $document->pdf($this->ownPayslip($request, $number));
    }

    /**
     * Wallet: available (issued payslips not withdrawn), reserved (a
     * withdrawal in progress), paid out, the minimum and the withdrawals.
     */
    public function wallet(Request $request, AgentPay $pay): JsonResponse
    {
        $agent = $this->agent($request)->loadMissing('carrier');
        $currency = app(CountryContext::class)->current()->currency;
        $balances = $pay->balances($agent, $currency);
        $minimum = $pay->minimum($currency);
        $withdrawals = DeliveryWithdrawal::query()->where('delivery_agent_id', $agent->id)->latest('id')->limit(50)->get();
        $open = $withdrawals->contains(fn (DeliveryWithdrawal $withdrawal): bool => $withdrawal->status->isOpen());
        $money = fn ($amount): array => MoneyResource::of((string) $amount->getAmount(), $currency);

        return ApiResponse::success([
            'available' => $money($balances['available']),
            'reserved' => $money($balances['reserved']),
            'paid_out' => $money($balances['paid_out']),
            'minimum' => $money($minimum),
            'can_withdraw' => ! $open && $balances['available']->isPositive() && ! $balances['available']->isLessThan($minimum),
            'paid_by' => $agent->carrier_id === null ? 'nawy' : 'carrier',
            'carrier' => $agent->carrier?->name,
            'last_payout' => $withdrawals->first()?->maskedPayout(),
            'withdrawals' => $withdrawals->map(fn (DeliveryWithdrawal $withdrawal): array => AgentPayPresenter::withdrawal($withdrawal))->all(),
        ]);
    }

    /**
     * Withdraw to a bank account: `amount`, `account_holder`, `bank_name`, `iban`.
     */
    public function withdraw(Request $request, AgentPay $pay, MoneyFactory $money): JsonResponse
    {
        $data = $request->validate(self::withdrawalRules());
        $agent = $this->agent($request);
        $currency = app(CountryContext::class)->current()->currency;

        return $this->refusable(fn (): JsonResponse => ApiResponse::success(AgentPayPresenter::withdrawal(
            $pay->requestWithdrawal($agent, $money->of((string) $data['amount'], $currency), $data, $request->user()->id),
        ), status: 201));
    }

    public function cancelWithdrawal(Request $request, string $number, AgentPay $pay): JsonResponse
    {
        $withdrawal = DeliveryWithdrawal::query()->where('delivery_agent_id', $this->agent($request)->id)->where('number', $number)->firstOrFail();

        return $this->refusable(fn (): JsonResponse => ApiResponse::success(AgentPayPresenter::withdrawal($pay->cancel($withdrawal, $request->user()->id))));
    }

    /**
     * Checks a scanned parcel label (or a typed number) against the
     * assignment ({@see ParcelBarcode}).
     */
    public function scan(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);
        $assignment = $this->assignment($request, $number)->load(['shipment', 'subOrder.order']);

        return match (ParcelBarcode::match($assignment, $data['code'])) {
            'match' => ApiResponse::success(['matches' => true, 'shipment_number' => $assignment->shipment?->number]),
            'bad_label' => ApiResponse::error('bad_label', __('This label did not scan cleanly. Scan it again.'), 422),
            default => ApiResponse::error('wrong_parcel', __('This parcel is not the one for this delivery.'), 422),
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function withdrawalRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'account_holder' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            'iban' => ['required', 'string', 'max:42', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! AgentPay::validIban((string) $value)) {
                    $fail(__('Enter a valid IBAN.'));
                }
            }],
        ];
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
        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->accept($this->assignment($request, $number), $request->user()->id)));
    }

    public function decline(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->decline($this->assignment($request, $number), $request->user()->id, $data['reason'])));
    }

    public function start(Request $request, string $number): JsonResponse
    {
        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->start($this->assignment($request, $number), $request->user()->id)));
    }

    public function arrive(Request $request, string $number): JsonResponse
    {
        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->arrive($this->assignment($request, $number))));
    }

    public function proof(Request $request, string $number, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate($this->proofRules($settings) + [
            'photo' => ['required_without_all:signature,signature_data', 'nullable', 'image', 'max:'.$settings->proofMaxKilobytes()],
        ]);

        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->attachProof($this->assignment($request, $number), $request->file('photo'), $request->file('signature'), $data['signature_data'] ?? null)));
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

        return $this->refusable(function () use ($request, $assignment, $data): JsonResponse {
            if ($request->hasFile('photo') || $request->hasFile('signature') || isset($data['signature_data'])) {
                $this->assignments->attachProof($assignment, $request->file('photo'), $request->file('signature'), $data['signature_data'] ?? null);
            }

            return $this->present($this->assignments->confirmDelivery($assignment, $request->user()->id, true));
        });
    }

    public function fail(Request $request, string $number, DeliverySettings $settings): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in($settings->failureReasons())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->refusable(fn (): JsonResponse => $this->present($this->assignments->fail($this->assignment($request, $number), $request->user()->id, $data['reason'], $data['note'] ?? null)));
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

    private function ownPayslip(Request $request, string $number): DeliveryPayslip
    {
        return DeliveryPayslip::query()->where('delivery_agent_id', $this->agent($request)->id)->where('number', $number)->firstOrFail();
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
            // QA-042: money as {amount, currency}, like me/cod.
            'cod_in_hand' => MoneyResource::make((string) CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->sum('amount')),
        ];
    }
}
