<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\CodSettlementStatus;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Finance\Support\Payouts;
use App\Domains\Finance\Support\PayoutStatement;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Finance → Payouts (SRS §4.1.12, Figma 98:4242): the selected
 * country's vendor statements with KPIs (to approve, on hold, COD
 * outstanding), the statement detail and the maker-checker actions —
 * approve (not by the admin who generated it), hold / release,
 * recalculate, process, complete with the bank reference, fail.
 */
class PayoutController extends Controller
{
    public function __construct(private Payouts $payouts, private CountryContext $countryContext, private MoneyFactory $money) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(PayoutStatus::class)]]);
        $currency = $this->countryContext->current()->currency;

        return view('panels.admin.finance.payouts', [
            'payouts' => VendorPayout::query()->with('vendor:id,store_name')
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'kpis' => [
                'pending' => $this->money->of((string) VendorPayout::query()->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Processing])->sum('total'), $currency),
                'pending_count' => VendorPayout::query()->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Processing])->count(),
                'on_hold' => $this->money->of((string) VendorPayout::query()->where('status', PayoutStatus::OnHold)->sum('total'), $currency),
                'on_hold_count' => VendorPayout::query()->where('status', PayoutStatus::OnHold)->count(),
                'cod_outstanding' => $this->money->of((string) CodSettlement::query()->whereIn('status', [CodSettlementStatus::Pending, CodSettlementStatus::Disputed])->sum('expected_amount'), $currency),
                'cod_open' => CodSettlement::query()->whereIn('status', [CodSettlementStatus::Pending, CodSettlementStatus::Disputed])->count(),
            ],
        ]);
    }

    public function show(int $payout, PayoutStatement $statement, CountryClock $clock): View
    {
        $payout = VendorPayout::query()->with(['vendor', 'transitions'])->findOrFail($payout);

        return view('panels.admin.finance.payout', [
            'payout' => $payout,
            'statement' => $statement->summarize($payout),
            'country' => $this->countryContext->current(),
            'clock' => $clock,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $created = $this->payouts->generate($this->countryContext->current(), true, $request->user()->id);

        return back()->with('status', trans_choice('{0} No vendor has an eligible balance.|{1} :count statement generated.|[2,*] :count statements generated.', $created, ['count' => $created]));
    }

    public function approve(Request $request, int $payout): RedirectResponse
    {
        $this->payouts->approve($this->payout($payout), $request->user()->id);

        return back()->with('status', __('Statement approved.'));
    }

    public function hold(Request $request, int $payout): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->payouts->hold($this->payout($payout), $request->user()->id, $reason);

        return back()->with('status', __('Statement put on hold.'));
    }

    public function release(Request $request, int $payout): RedirectResponse
    {
        $this->payouts->release($this->payout($payout), $request->user()->id);

        return back()->with('status', __('Statement released.'));
    }

    public function recalculate(Request $request, int $payout): RedirectResponse
    {
        $this->payouts->recalculate($this->payout($payout), $request->user()->id);

        return back()->with('status', __('Statement recalculated.'));
    }

    public function process(Request $request, int $payout): RedirectResponse
    {
        $this->payouts->process($this->payout($payout), $request->user()->id);

        return back()->with('status', __('Bank transfer marked as sent.'));
    }

    public function complete(Request $request, int $payout): RedirectResponse
    {
        $reference = $request->validate(['bank_reference' => ['required', 'string', 'max:100']])['bank_reference'];
        $this->payouts->complete($this->payout($payout), $request->user()->id, $reference);

        return back()->with('status', __('Payout completed.'));
    }

    public function fail(Request $request, int $payout): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $this->payouts->fail($this->payout($payout), $request->user()->id, $reason);

        return back()->with('status', __('Payout marked as failed.'));
    }

    private function payout(int $id): VendorPayout
    {
        return VendorPayout::query()->findOrFail($id);
    }
}
