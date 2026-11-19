<?php

namespace App\Domains\Vendors\Http\Controllers\Admin;

use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Vendors\Enums\ChangeRequestSection;
use App\Domains\Vendors\Enums\ChangeRequestStatus;
use App\Domains\Vendors\Models\ChangeRequest;
use App\Domains\Vendors\Support\ChangeRequests;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Commerce → Profile change requests (lifecycle catalogue §7.3):
 * queue of locked-section changes from stores and travel agencies with the
 * current and requested values side by side; approve (bank changes need
 * the "bank account verified" confirmation) or reject with a reason.
 */
class ChangeRequestAdminController extends Controller
{
    public function __construct(private ChangeRequests $changes, private AdminVendorScope $scope) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ChangeRequestStatus::class)],
            'section' => ['nullable', Rule::enum(ChangeRequestSection::class)],
        ]);
        $status = $filters['status'] ?? ChangeRequestStatus::Pending->value;

        return view('panels.admin.change-requests.index', [
            'requests' => $this->scope->apply(ChangeRequest::query())->with('vendor')->where('status', $status)
                ->when($filters['section'] ?? null, fn ($query, string $section) => $query->where('section', $section))
                ->oldest('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'filters' => $filters,
        ]);
    }

    public function show(int $changeRequest): View
    {
        return view('panels.admin.change-requests.show', [
            'changeRequest' => $this->scope->apply(ChangeRequest::query())->with(['vendor', 'transitions'])->findOrFail($changeRequest),
        ]);
    }

    public function approve(Request $request, int $changeRequest): RedirectResponse
    {
        $changeRequest = $this->scope->apply(ChangeRequest::query())->findOrFail($changeRequest);
        $request->validate(['bank_verified' => ['boolean']]);

        try {
            $this->changes->approve($changeRequest, $request->user()->id, $request->boolean('bank_verified'));
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Change applied.'));
    }

    public function reject(Request $request, int $changeRequest): RedirectResponse
    {
        $changeRequest = $this->scope->apply(ChangeRequest::query())->findOrFail($changeRequest);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        try {
            $this->changes->reject($changeRequest, $request->user()->id, $reason);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Request rejected.'));
    }
}
