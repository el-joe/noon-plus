<?php

namespace App\Domains\Vendors\Http\Controllers;

use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Store settings / Profile (SRS §4.3 "My Profile"): the
 * store as registered through the portal wizard. Contact phone and the
 * store description are editable; legal details (store name, address,
 * licence) are locked and change through Nawy (change requests).
 */
class StoreSettingsController extends Controller
{
    public function __construct(private VendorWorkspace $workspace) {}

    public function show(): View
    {
        return view('panels.partner.store', ['vendor' => $this->workspace->vendor()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $data = $request->validate([
            'contact_phone' => ['required', 'string', 'max:24', new CountryPhone],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $details = $vendor->business_details ?? [];
        $details['store'] = array_merge($details['store'] ?? [], ['description' => $data['description'] ?? null]);
        $vendor->forceFill(['contact_phone' => CountryPhone::normalize($data['contact_phone']), 'business_details' => $details])->save();

        return back()->with('status', __('Store settings saved.'));
    }
}
