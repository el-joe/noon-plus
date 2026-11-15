<?php

namespace App\Domains\Listings\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminVendorScope;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Listings\Support\ListingStateMachine;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → Approvals queue, Listings tab (SRS §4.1.5 vendor listings, §5.2;
 * Figma 96:2483): approve or reject (with a reason) listings waiting for
 * review in the selected country. Times are shown in Oman time.
 */
class ListingReviewController extends Controller
{
    public function __construct(
        private ListingStateMachine $stateMachine,
        private InventoryLedger $ledger,
        private CountryContext $countryContext,
    ) {}

    public function index(CountryClock $clock): View
    {
        $listings = app(AdminVendorScope::class)->apply(Listing::query())
            ->where('status', ListingStatus::PendingReview)
            ->with(['vendor', 'variant.product.category'])
            ->withAvailableQuantity()
            ->withMax(['statusHistory as submitted_at' => fn ($query) => $query->where('to_status', ListingStatus::PendingReview)], 'created_at')
            ->orderBy('submitted_at')
            ->paginate(25);

        return view('panels.admin.approvals.index', [
            'listings' => $listings,
            'country' => $this->countryContext->current(),
            'clock' => $clock,
        ]);
    }

    public function approve(Request $request, int $listing): RedirectResponse
    {
        $listing = app(AdminVendorScope::class)->apply(Listing::query())->with(['vendor', 'variant.product'])->findOrFail($listing);

        try {
            if ($listing->variant->product->status !== 'active') {
                throw ListingTransitionRefused::because(__('The catalogue product is not active.'));
            }

            DB::connection($listing->getConnectionName())->transaction(function () use ($listing, $request): void {
                $this->stateMachine->transition($listing, ListingStatus::Active, $request->user()->id, 'Approved');

                if ($this->ledger->available($listing) <= 0) {
                    $this->stateMachine->transition($listing, ListingStatus::OutOfStock, $request->user()->id, 'No stock at approval');
                }
            });
        } catch (ListingTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Listing approved.'));
    }

    public function reject(Request $request, int $listing): RedirectResponse
    {
        $listing = app(AdminVendorScope::class)->apply(Listing::query())->findOrFail($listing);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        try {
            $this->stateMachine->transition($listing, ListingStatus::Rejected, $request->user()->id, $reason);
        } catch (ListingTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Listing rejected; the seller sees your reason.'));
    }
}
