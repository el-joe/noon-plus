<?php

namespace App\Domains\Finance\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\CodSettlementStatus;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Finance\Support\CodSettlements;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Finance → COD settlements (SRS §4.1.12, §8): cash remitted per
 * agent and day; marking one settled unlocks the vendor payouts of its
 * sub-orders; a different amount opens it as disputed.
 */
class CodSettlementController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(CodSettlementStatus::class)]]);

        return view('panels.admin.finance.cod-settlements', [
            'settlements' => CodSettlement::query()
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('business_date')->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function settle(Request $request, int $settlement, CodSettlements $settlements, MoneyFactory $money): RedirectResponse
    {
        $country = $this->countryContext->current();
        $decimals = $money->zero($country->currency)->getCurrency()->getDefaultFractionDigits();
        $data = $request->validate([
            'remitted_amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,'.$decimals.'})?$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $result = $settlements->settle(CodSettlement::query()->findOrFail($settlement), $money->of($data['remitted_amount'], $country->currency), $request->user()->id, $data['note'] ?? null);

        return back()->with('status', $result->status === CodSettlementStatus::Settled
            ? __('Settlement :number settled — vendor payouts unlocked.', ['number' => $result->number])
            : __('The remitted cash differs; settlement :number is disputed.', ['number' => $result->number]));
    }
}
