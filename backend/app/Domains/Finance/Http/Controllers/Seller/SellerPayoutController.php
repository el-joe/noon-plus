<?php

namespace App\Domains\Finance\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\PayoutStatement;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Seller Center → Payouts / Statements (SRS §4.3, Figma 100:17344): the
 * store's balances (awaiting COD remittance, next statement, on statements
 * being paid), its statements and the statement detail.
 */
class SellerPayoutController extends Controller
{
    public function __construct(private VendorWorkspace $workspace, private CountryContext $countryContext, private Ledger $ledger) {}

    public function index(): View
    {
        $vendor = $this->workspace->vendor();
        $currency = $this->countryContext->current()->currency;

        return view('panels.partner.payouts.index', [
            'payouts' => VendorPayout::query()->where('vendor_id', $vendor->id)->latest('id')->paginate(20),
            'country' => $this->countryContext->current(),
            'vendor' => $vendor,
            'balances' => [
                'pending' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Pending), $currency),
                'spendable' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Spendable), $currency),
                'reserved' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Reserved), $currency),
            ],
            'awaitingCod' => VendorBalanceItem::query()->where('vendor_id', $vendor->id)->where('is_eligible', false)->count(),
        ]);
    }

    public function show(int $payout, PayoutStatement $statement): View
    {
        $vendor = $this->workspace->vendor();
        $payout = VendorPayout::query()->where('vendor_id', $vendor->id)->with('transitions')->findOrFail($payout);

        return view('panels.partner.payouts.show', [
            'payout' => $payout,
            'statement' => $statement->summarize($payout),
            'country' => $this->countryContext->current(),
            'previous' => VendorPayout::query()->where('vendor_id', $vendor->id)->where('id', '<', $payout->id)->latest('id')->limit(3)->get(),
        ]);
    }
}
