<?php

namespace App\Domains\Reporting\Http\Controllers\Admin;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Reporting\Support\CrossCountryReports;
use App\Domains\Reporting\Support\ReportingEtl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Reports → Cross-country (FR-CTY-10, FR-ANL-2). Figures come from
 * the reporting database (loaded nightly), never from the live country
 * databases; money stays per currency. An admin sees the countries where
 * they hold `reports.view` (super admins: every country). A super admin may
 * reload a day now.
 */
class CrossCountryReportController extends Controller
{
    public function __construct(private CrossCountryReports $reports, private AdminAccess $access, private CountryContext $countryContext) {}

    public function index(Request $request): View|StreamedResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'size:3'],
            'export' => ['nullable', 'in:csv'],
        ]);
        $to = CarbonImmutable::parse($filters['to'] ?? CarbonImmutable::now(config('nawy.admin_timezone'))->subDay()->toDateString());
        $from = CarbonImmutable::parse($filters['from'] ?? $to->subDays(29)->toDateString());
        $allowed = $this->allowedCountries($request->user());
        $codes = array_values(array_intersect($filters['countries'] ?? $allowed->pluck('code')->all(), $allowed->pluck('code')->all()));

        if (($filters['export'] ?? null) === 'csv') {
            return $this->csv($codes, $from, $to);
        }

        return view('panels.admin.reports.cross-country', [
            'from' => $from,
            'to' => $to,
            'countries' => $allowed->keyBy('code'),
            'selected' => $codes,
            'sales' => $this->reports->sales($codes, $from, $to),
            'sellers' => $this->reports->sellers($codes, $from, $to),
            'marketers' => $this->reports->marketers($codes, $from, $to),
            'payouts' => $this->reports->payouts($codes, $from, $to),
            'revenue' => $this->reports->revenue($codes, $from, $to),
            'trend' => $this->reports->gmvTrend($codes, $from, $to),
            'runs' => $this->reports->lastRuns($allowed->pluck('code')->all()),
            'canRun' => $this->access->isSuperAdmin($request->user()),
        ]);
    }

    public function run(Request $request, ReportingEtl $etl): RedirectResponse
    {
        abort_unless($this->access->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $batch = (string) Str::uuid();
        $failed = 0;

        foreach (Country::query()->active()->orderBy('id')->get() as $country) {
            $day = isset($data['date']) ? CarbonImmutable::parse($data['date']) : $etl->yesterday($country);
            $failed += $etl->load($country, $day, $day, 'manual', $request->user()->id, $batch)->status === 'failed' ? 1 : 0;
        }

        return back()->with('status', $failed === 0 ? __('Reporting data reloaded.') : trans_choice(':count country failed to load; see the run status.|:count countries failed to load; see the run status.', $failed));
    }

    /**
     * Countries the admin may report on (central database only).
     *
     * @return Collection<int, Country>
     */
    private function allowedCountries(User $user): Collection
    {
        $countries = Country::query()->active()->orderBy('id')->get();

        if ($this->access->isSuperAdmin($user)) {
            return $countries;
        }

        $team = getPermissionsTeamId();
        $allowed = $countries->filter(fn (Country $country): bool => $this->countryContext->runAs($country, fn (): bool => $this->access->allows($user, AdminPermission::ReportsView)))->values();
        setPermissionsTeamId($team);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $allowed;
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function csv(array $codes, CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        $rows = $this->reports->dailySales($codes, $from, $to);
        $columns = ['report_date', 'country_code', 'currency', 'orders_count', 'sub_orders_count', 'items_sold', 'gmv', 'discount_total', 'shipping_total',
            'average_order_value', 'cancelled_orders', 'cancelled_value', 'unique_buyers', 'new_buyers', 'cod_orders', 'returns_requested', 'refunds_count', 'refunds_amount'];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $column): string => substr((string) $row->{$column}, 0, $column === 'report_date' ? 10 : 64), $columns));
            }

            fclose($out);
        }, "nawy-sales-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }
}
