<?php

namespace App\Domains\Delivery\Http\Controllers\Admin;

use App\Domains\Delivery\Enums\AgentWithdrawalStatus;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Delivery\Support\AgentPay;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Delivery & logistics → Agent withdrawals (four-eyes, same
 * permissions as vendor payouts: `payouts.view`, `payouts.approve`,
 * `payouts.process`). Admin reviews Nawy fleet drivers; a carrier's
 * drivers are reviewed and paid by the carrier (shown here read-only).
 */
class AgentWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AgentWithdrawalStatus::class)], 'scope' => ['nullable', Rule::in(['fleet', 'carrier', 'all'])]]);
        $scope = $filters['scope'] ?? 'fleet';

        return view('panels.shared.agent-withdrawals', [
            'withdrawals' => DeliveryWithdrawal::query()->with(['agent', 'carrier'])
                ->when($scope === 'fleet', fn ($query) => $query->whereNull('carrier_id'))
                ->when($scope === 'carrier', fn ($query) => $query->whereNotNull('carrier_id'))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters + ['scope' => $scope],
            'panel' => 'admin',
            'actionRoute' => 'admin.delivery.withdrawals.update',
            'canApprove' => $request->user()->can('payouts.approve'),
            'canPay' => $request->user()->can('payouts.process'),
            'fourEyes' => true,
            'breadcrumbs' => [__('Operations'), __('Delivery & logistics'), __('Agent withdrawals')],
        ]);
    }

    public function update(Request $request, int $withdrawal, AgentPay $pay): RedirectResponse
    {
        $withdrawal = DeliveryWithdrawal::query()->findOrFail($withdrawal);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'pay', 'reject'])], 'reference' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'string', 'max:255']]);

        if ($withdrawal->carrier_id !== null) {
            throw new TransitionRefused(__('The driver\'s carrier approves and pays this withdrawal.'));
        }

        abort_unless($request->user()->can($data['action'] === 'pay' ? 'payouts.process' : 'payouts.approve'), 403);
        $userId = $request->user()->id;

        match ($data['action']) {
            'approve' => $pay->approve($withdrawal, $userId),
            'pay' => $pay->markPaid($withdrawal, $userId, (string) ($data['reference'] ?? '')),
            default => $pay->reject($withdrawal, $userId, (string) ($data['reason'] ?? '')),
        };

        return back()->with('status', __('Withdrawal :number: :status.', ['number' => $withdrawal->number, 'status' => $withdrawal->refresh()->status->value]));
    }
}
