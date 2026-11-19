<?php

namespace App\Domains\Vendors\Http\Controllers;

use App\Domains\Vendors\Enums\ChangeRequestSection;
use App\Domains\Vendors\Models\ChangeRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\ChangeRequests;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller Center / Travel Partner → Change requests (lifecycle catalogue
 * §7.3): the locked profile sections with their current values, a form per
 * section to ask for a change, and the requests with their decision. The
 * route default `panel` picks the requester: partner = the store,
 * travel_agency = the agency.
 */
class ChangeRequestController extends Controller
{
    public function __construct(private ChangeRequests $changes) {}

    public function index(Request $request): View
    {
        $vendor = $this->vendor($request);

        return view('panels.shared.change-requests', [
            'panel' => $this->panel($request),
            'vendor' => $vendor,
            'sections' => collect(ChangeRequestSection::cases())->mapWithKeys(fn (ChangeRequestSection $section): array => [$section->value => [
                'section' => $section,
                'current' => $this->changes->currentValues($vendor, $section),
            ]]),
            'requests' => ChangeRequest::query()->where('vendor_id', $vendor->id)->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $this->vendor($request);
        $section = ChangeRequestSection::from((string) $request->validate(['section' => ['required', Rule::enum(ChangeRequestSection::class)]])['section']);

        if ($section === ChangeRequestSection::BankDetails && is_string($request->input('values.iban'))) {
            $request->merge(['values' => ['iban' => strtoupper(str_replace(' ', '', $request->input('values.iban')))] + (array) $request->input('values')]);
        }

        $data = $request->validate(collect($section->fields())->mapWithKeys(fn (array $rules, string $field): array => ["values.{$field}" => $rules])->all()
            + ['note' => ['nullable', 'string', 'max:1000']]);
        $values = $data['values'] ?? [];

        try {
            $created = $this->changes->submit($vendor, $this->panel($request) === 'travel_agency' ? 'travel_agency' : 'vendor', $request->user()->id, $section, $values, $data['note'] ?? null);
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Change request :number sent.', ['number' => $created->number]));
    }

    public function cancel(Request $request, int $changeRequest): RedirectResponse
    {
        try {
            $this->changes->cancel(ChangeRequest::query()->where('vendor_id', $this->vendor($request)->id)->findOrFail($changeRequest), $request->user()->id);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Change request cancelled.'));
    }

    private function vendor(Request $request): Vendor
    {
        return $this->panel($request) === 'travel_agency' ? $request->attributes->get('agency') : app(VendorWorkspace::class)->vendor();
    }

    private function panel(Request $request): string
    {
        return (string) ($request->route()?->defaults['panel'] ?? 'partner');
    }
}
