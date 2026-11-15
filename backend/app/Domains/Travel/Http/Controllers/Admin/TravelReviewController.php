<?php

namespace App\Domains\Travel\Http\Controllers\Admin;

use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\TravelPackages;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Travel packages review (SRS §12.2): approve or reject packages
 * waiting for review; the agency's trip contract file is downloadable here
 * (private disk).
 */
class TravelReviewController extends Controller
{
    public function __construct(private TravelPackages $packages) {}

    public function index(): View
    {
        return view('panels.admin.travel.review', [
            'packages' => TravelPackage::query()->where('status', PackageStatus::PendingReview)->with(['vendor', 'departures.rooms'])->orderBy('submitted_at')->paginate(25),
        ]);
    }

    public function approve(Request $request, int $package): RedirectResponse
    {
        $this->packages->approve(TravelPackage::query()->findOrFail($package), $request->user()->id);

        return back()->with('status', __('Package approved and on sale.'));
    }

    public function reject(Request $request, int $package): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $this->packages->reject(TravelPackage::query()->findOrFail($package), $request->user()->id, $reason);

        return back()->with('status', __('Package rejected.'));
    }

    public function contract(int $package): StreamedResponse
    {
        $package = TravelPackage::query()->whereNotNull('contract_path')->findOrFail($package);

        return Storage::disk(TravelPackages::DISK)->download($package->contract_path, 'trip-contract-'.$package->id.'.'.pathinfo($package->contract_path, PATHINFO_EXTENSION));
    }
}
