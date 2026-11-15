<?php

namespace App\Domains\FlashSales\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\FlashSaleSubmissionMachine;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Brick\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller Center → Flash sales (SRS §4.3 Marketing, §10): offer own active
 * listings to events open for submissions, see the review outcome, withdraw
 * before the event goes live (lifecycle catalogue §4.11).
 */
class SellerFlashSaleController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private FlashSaleSubmissionMachine $machine,
        private CountryContext $countryContext,
    ) {}

    public function index(CountryClock $clock): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.flash-sales.index', [
            'country' => $this->countryContext->current(),
            'clock' => $clock,
            'openEvents' => FlashSaleEvent::query()->where('status', FlashSaleEventStatus::SubmissionOpen)->orderBy('starts_at')->get(),
            'listings' => Listing::query()->where('vendor_id', $vendor->id)->where('status', ListingStatus::Active)->with('variant.product')->withAvailableQuantity()->get(),
            'submissions' => FlashSaleSubmission::query()->where('vendor_id', $vendor->id)->with(['event', 'listing.variant.product'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request, int $event): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $event = FlashSaleEvent::query()->findOrFail($event);
        $decimals = Currency::of($this->countryContext->current()->currency)->getDefaultFractionDigits();

        $data = $request->validate([
            'listing_id' => ['required', 'integer', Rule::exists(Listing::class, 'id')->where('vendor_id', $vendor->id)],
            'flash_price' => ['required', 'decimal:0,'.$decimals, 'gt:0'],
            'quantity_cap' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $listing = Listing::query()->where('vendor_id', $vendor->id)->findOrFail($data['listing_id']);

        try {
            $this->machine->submit($event, $listing, (string) $data['flash_price'], (int) $data['quantity_cap'], $request->user()->id);
        } catch (FlashSaleTransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Offer submitted to :event.', ['event' => $event->name]));
    }

    public function withdraw(Request $request, int $submission): RedirectResponse
    {
        $submission = FlashSaleSubmission::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($submission);

        try {
            $this->machine->transition($submission, FlashSaleSubmissionStatus::Withdrawn, ActorType::Vendor, $request->user()->id, 'Withdrawn by the seller');
        } catch (FlashSaleTransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Offer withdrawn.'));
    }
}
