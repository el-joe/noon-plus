<?php

namespace App\Domains\Delivery\Http\Controllers\Panel;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Http\Controllers\Api\DeliveryAgentApiController;
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
use App\Domains\Delivery\Support\PayslipDocument;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery agent web panel (delivery., SRS §4.6, Figma "delivery my day"):
 * my day with the availability toggle, my assignments with the delivery
 * code and the delivery steps, and my COD cash. Same rules as the app.
 */
class DeliveryPanelController extends Controller
{
    public function __construct(private AgentWorkspace $workspace, private AgentAssignments $assignments) {}

    public function dashboard(Request $request): View
    {
        $agent = $this->workspace->agentFor($request->user());

        if ($agent === null) {
            return app(PanelDashboardController::class)($request);
        }

        return view('panels.delivery.dashboard', [
            'agent' => $agent,
            'open' => DeliveryAssignment::query()->where('delivery_agent_id', $agent->id)->whereIn('status', AssignmentStatus::open())->with('subOrder.order')->orderBy('sla_due_at')->get(),
            'deliveredToday' => DeliveryAssignment::query()->where('delivery_agent_id', $agent->id)->where('status', AssignmentStatus::Delivered)->where('delivered_at', '>=', now()->startOfDay())->count(),
            'codInHand' => (string) CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->sum('amount'),
        ]);
    }

    public function availability(Request $request, DeliveryAgents $agents): RedirectResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $agents->setAvailable($this->agent($request), (bool) $data['available'], $request->user()->id);

        return back()->with('status', $data['available'] ? __('You are available for new deliveries.') : __('You are unavailable.'));
    }

    public function index(Request $request): View
    {
        return view('panels.delivery.assignments', [
            'assignments' => DeliveryAssignment::query()->where('delivery_agent_id', $this->agent($request)->id)->with('subOrder.order')->latest('id')->paginate(30),
        ]);
    }

    public function show(Request $request, string $number, DeliverySettings $settings): View
    {
        $assignment = $this->workspace->assignment($this->agent($request), $number)->load(['subOrder.order', 'subOrder.vendor', 'subOrder.items']);

        return view('panels.delivery.assignment', [
            'assignment' => $assignment,
            'needsProof' => $assignment->status->isOpen() && $this->assignments->needsProof($assignment),
            'proofType' => $settings->proofType(),
            'reasons' => $settings->failureReasons(),
        ]);
    }

    public function act(Request $request, string $number, string $action, DeliverySettings $settings): RedirectResponse
    {
        $assignment = $this->workspace->assignment($this->agent($request), $number);
        $userId = $request->user()->id;

        match ($action) {
            'accept' => $this->assignments->accept($assignment, $userId),
            'decline' => $this->assignments->decline($assignment, $userId, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason']),
            'start' => $this->assignments->start($assignment, $userId),
            'arrive' => $this->assignments->arrive($assignment),
            'proof' => $this->proof($request, $assignment, $settings),
            'confirm' => $this->confirm($request, $assignment, $settings),
            'fail' => $this->fail($request, $assignment, $settings),
        };

        return redirect()->route('delivery.assignments.show', $number)->with('status', __('Saved.'));
    }

    public function cod(Request $request): View
    {
        $agent = $this->agent($request);

        return view('panels.delivery.cod', [
            'collections' => CodCollection::query()->where('agent_user_id', $agent->user_id)->whereNull('cod_settlement_id')->latest('id')->get(),
            'settlements' => CodSettlement::query()->where('agent_user_id', $agent->user_id)->latest('business_date')->limit(30)->get(),
        ]);
    }

    /**
     * Earnings (Figma 108:8009): the last 7 days at the country's pay rates
     * ({@see AgentEarnings}). Payouts of these earnings are not built yet.
     */
    public function earnings(Request $request, AgentEarnings $earnings, AgentPay $pay): View
    {
        $agent = $this->agent($request);
        $summary = $earnings->lastSevenDays($agent);
        $currency = $this->countryContext()->current()->currency;
        $withdrawals = DeliveryWithdrawal::query()->where('delivery_agent_id', $agent->id)->latest('id')->limit(30)->get();

        return view('panels.delivery.earnings', [
            'agent' => $agent->loadMissing('carrier'),
            'payslips' => DeliveryPayslip::query()->where('delivery_agent_id', $agent->id)->latest('period_end')->latest('id')->limit(30)->get(),
            'wallet' => $pay->balances($agent, $currency),
            'minimum' => $pay->minimum($currency),
            'withdrawals' => $withdrawals,
            'openWithdrawal' => $withdrawals->first(fn (DeliveryWithdrawal $withdrawal): bool => $withdrawal->status->isOpen()),
            'lastPayout' => $withdrawals->first()?->maskedPayout(),
            'days' => $summary['days'],
            'perStop' => $summary['per_stop'],
            'perFailed' => $summary['per_failed'],
            'total' => $summary['total'],
            'stops' => $summary['stops'],
            'failedTotal' => $summary['failed'],
        ]);
    }

    public function payslip(Request $request, string $number, PayslipDocument $document): \Illuminate\Contracts\View\View
    {
        return $document->view($this->ownPayslip($request, $number));
    }

    public function payslipPdf(Request $request, string $number, PayslipDocument $document): Response
    {
        return $document->pdf($this->ownPayslip($request, $number));
    }

    public function withdraw(Request $request, AgentPay $pay, MoneyFactory $money): RedirectResponse
    {
        $data = $request->validate(DeliveryAgentApiController::withdrawalRules());
        $withdrawal = $pay->requestWithdrawal($this->agent($request), $money->of((string) $data['amount'], $this->countryContext()->current()->currency), $data, $request->user()->id);

        return back()->with('status', __('Withdrawal :number requested.', ['number' => $withdrawal->number]));
    }

    public function cancelWithdrawal(Request $request, string $number, AgentPay $pay): RedirectResponse
    {
        $withdrawal = DeliveryWithdrawal::query()->where('delivery_agent_id', $this->agent($request)->id)->where('number', $number)->firstOrFail();
        $pay->cancel($withdrawal, $request->user()->id);

        return back()->with('status', __('Withdrawal :number cancelled.', ['number' => $withdrawal->number]));
    }

    private function ownPayslip(Request $request, string $number): DeliveryPayslip
    {
        return DeliveryPayslip::query()->where('delivery_agent_id', $this->agent($request)->id)->where('number', $number)->firstOrFail();
    }

    private function countryContext(): CountryContext
    {
        return app(CountryContext::class);
    }

    public function profile(Request $request): View
    {
        $agent = $this->agent($request);

        return view('panels.delivery.profile', ['agent' => $agent->load('carrier')]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24', new CountryPhone],
            'vehicle_type' => ['nullable', Rule::in(['bike', 'car', 'van', 'truck'])],
            'vehicle_plate' => ['nullable', 'string', 'max:24'],
        ]);
        $agent->forceFill(['phone' => CountryPhone::normalize($data['phone'])] + array_intersect_key($data, array_flip(['vehicle_type', 'vehicle_plate'])))->save();

        return back()->with('status', __('Profile saved.'));
    }

    public function documents(Request $request, Documents $documents): View
    {
        $agent = $this->agent($request);

        return view('panels.delivery.documents', [
            'requirements' => $documents->requirements(DocumentOwner::DeliveryAgent),
            'uploads' => Document::query()->where('owner_type', DocumentOwner::DeliveryAgent->value)->where('owner_id', $agent->id)->latest('id')->get(),
        ]);
    }

    public function storeDocument(Request $request, Documents $documents): RedirectResponse
    {
        $agent = $this->agent($request);
        $type = $documents->requirements(DocumentOwner::DeliveryAgent)->firstWhere('id', $request->integer('document_type_id')) ?? abort(404);
        $data = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.(1024 * min(20, (int) ($type->max_mb ?: 10)))],
            'valid_until' => [$type->has_expiry ? 'required' : 'nullable', 'date', 'after:today'],
        ]);
        $documents->upload($type, DocumentOwner::DeliveryAgent->value, $agent->id, $request->file('file'), $request->user()->id, null, $data['valid_until'] ?? null);

        return back()->with('status', __('Uploaded — Nawy will review it.'));
    }

    private function proof(Request $request, DeliveryAssignment $assignment, DeliverySettings $settings): DeliveryAssignment
    {
        $data = $request->validate([
            'photo' => ['nullable', 'image', 'max:'.$settings->proofMaxKilobytes()],
            'signature_data' => ['nullable', 'string', 'max:'.($settings->proofMaxKilobytes() * 1400)],
        ]);

        return $this->assignments->attachProof($assignment, $request->file('photo'), null, ($data['signature_data'] ?? '') === '' ? null : $data['signature_data']);
    }

    private function confirm(Request $request, DeliveryAssignment $assignment, DeliverySettings $settings): DeliveryAssignment
    {
        $request->validate(['codes_match' => ['required', 'accepted']]);

        if ($request->hasFile('photo') || $request->filled('signature_data')) {
            $this->proof($request, $assignment, $settings);
        }

        return $this->assignments->confirmDelivery($assignment, $request->user()->id, true);
    }

    private function fail(Request $request, DeliveryAssignment $assignment, DeliverySettings $settings): DeliveryAssignment
    {
        $data = $request->validate(['reason' => ['required', Rule::in($settings->failureReasons())], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->assignments->fail($assignment, $request->user()->id, $data['reason'], $data['note'] ?? null);
    }

    private function agent(Request $request): DeliveryAgent
    {
        return $request->attributes->get('delivery_agent');
    }
}
