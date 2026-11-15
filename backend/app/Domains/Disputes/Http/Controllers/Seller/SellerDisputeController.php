<?php

namespace App\Domains\Disputes\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Disputes (QC-001, SRS §7.6): the store's disputes and
 * the thread; the seller's first reply moves an open dispute to
 * `seller_responded`. Another store's dispute is a 404.
 */
class SellerDisputeController extends Controller
{
    public function __construct(private Disputes $disputes, private VendorWorkspace $workspace, private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.partner.disputes.index', [
            'disputes' => Dispute::query()->where('vendor_id', $this->workspace->vendor()->id)->with('subOrder:id,number')->latest('id')->paginate(25),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function show(int $dispute): View
    {
        return view('panels.partner.disputes.show', [
            'dispute' => $this->find($dispute)->load(['messages', 'subOrder']),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function reply(Request $request, int $dispute): RedirectResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']])['body'];
        $this->disputes->message($this->find($dispute), ActorType::Vendor, $request->user()->id, $body);

        return back()->with('status', __('Reply sent.'));
    }

    private function find(int $id): Dispute
    {
        return Dispute::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
    }
}
