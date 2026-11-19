<?php

namespace App\Domains\Finance\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Support\SellerEarnings;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Seller Center → Finance → Earnings (SRS §4.3, §9): balances from the
 * ledger and the period's sales, deductions and net, with a table by month.
 * Finance permission group (`tenant.can:partner,finance`): owner, manager
 * (view) and finance staff only. Dates are country days.
 */
class SellerEarningsController extends Controller
{
    /** Longest custom period, in days. */
    private const MAX_DAYS = 731;

    public function __construct(private VendorWorkspace $workspace, private CountryContext $countryContext) {}

    public function __invoke(Request $request, SellerEarnings $earnings): View
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['this_month', 'last_month', 'last_3_months', 'this_year', 'last_12_months', 'custom'])],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
        ]);
        $country = $this->countryContext->current();
        $vendor = $this->workspace->vendor();
        $period = $data['period'] ?? 'last_12_months';
        $today = CarbonImmutable::now($country->timezone)->startOfDay();

        [$from, $to] = match ($period) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_3_months' => [$today->subMonthsNoOverflow(2)->startOfMonth(), $today],
            'this_year' => [$today->startOfYear(), $today],
            'custom' => [CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $country->timezone), CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $country->timezone)],
            default => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today],
        };

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => __('The end date must be on or after the start date.')]);
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Choose a period of at most two years.')]);
        }

        return view('panels.partner.earnings', [
            'country' => $country,
            'vendor' => $vendor,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'balances' => $earnings->balances($vendor, $country->currency),
            'report' => $earnings->period($vendor, $country, $from, $to),
        ]);
    }
}
