<?php

namespace App\Domains\Delivery\Http\Controllers\Carrier;

use App\Domains\Delivery\Enums\AgentWithdrawalStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentPay;
use App\Domains\Delivery\Support\ParcelLabels;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Support\Documents;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shipping panel: the company's drivers' wallet withdrawals (the carrier
 * pays its own drivers; four-eyes — the supervisor who approved cannot
 * mark it paid), the drivers' document status and expiry (Nawy reviews
 * the files), and parcel labels of the company's shipments.
 */
class CarrierPayController extends Controller
{
    public function withdrawals(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AgentWithdrawalStatus::class)]]);

        return view('panels.shared.agent-withdrawals', [
            'withdrawals' => DeliveryWithdrawal::query()->where('carrier_id', $this->carrier($request)->id)->with(['agent', 'carrier'])
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'panel' => 'carrier',
            'actionRoute' => 'carrier.withdrawals.update',
            'canApprove' => true,
            'canPay' => true,
            'fourEyes' => true,
            'breadcrumbs' => [__('Commercial'), __('Agent withdrawals')],
        ]);
    }

    public function updateWithdrawal(Request $request, int $withdrawal, AgentPay $pay): RedirectResponse
    {
        $withdrawal = DeliveryWithdrawal::query()->where('carrier_id', $this->carrier($request)->id)->findOrFail($withdrawal);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'pay', 'reject'])], 'reference' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'string', 'max:255']]);
        $userId = $request->user()->id;

        match ($data['action']) {
            'approve' => $pay->approve($withdrawal, $userId, ActorType::Carrier),
            'pay' => $pay->markPaid($withdrawal, $userId, (string) ($data['reference'] ?? ''), ActorType::Carrier),
            default => $pay->reject($withdrawal, $userId, (string) ($data['reason'] ?? ''), ActorType::Carrier),
        };

        return back()->with('status', __('Withdrawal :number: :status.', ['number' => $withdrawal->number, 'status' => $withdrawal->refresh()->status->value]));
    }

    public function documents(Request $request, Documents $documents): View
    {
        $agents = DeliveryAgent::query()->where('carrier_id', $this->carrier($request)->id)->orderBy('name')->get();

        return view('panels.carrier.driver-documents', [
            'agents' => $agents,
            'requirements' => $documents->requirements(DocumentOwner::DeliveryAgent),
            'uploads' => Document::query()->where('owner_type', DocumentOwner::DeliveryAgent->value)->whereIn('owner_id', $agents->pluck('id'))->latest('id')->get()
                ->groupBy('owner_id')->map(fn ($rows) => $rows->unique('document_type_id')->keyBy('document_type_id')),
        ]);
    }

    public function label(Request $request, int $shipment, ParcelLabels $labels): Response|ViewContract
    {
        $shipment = Shipment::query()->where('carrier_id', $this->carrier($request)->id)->findOrFail($shipment);

        return $request->routeIs('carrier.shipments.label-pdf') ? $labels->pdf($shipment) : $labels->view($shipment);
    }

    private function carrier(Request $request): Carrier
    {
        return $request->attributes->get('carrier');
    }
}
