<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Panels\Navigation\PanelNavigation;
use App\Domains\Panels\Support\AdminKpis;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Panels\Support\SystemHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin dashboard (QC-024, Figma `96:1693`): KPIs for the countries the
 * admin can see — GMV and orders today, active sellers, pending approvals —
 * with a total row per figure (GMV per currency only), country clocks, and
 * a CSV export of the same table. Admins with `finance.hidden` see no GMV.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, CountryClock $clock, AdminKpis $kpis, PanelCountries $panelCountries): View|StreamedResponse
    {
        $panel = Panel::find('admin');
        $countries = $panelCountries->for($request->user(), $panel);
        $rows = $kpis->forCountries($countries);
        $showMoney = ! app(AdminAccess::class)->allows($request->user(), AdminPermission::FinanceHidden);

        if ($request->query('export') === 'csv') {
            return response()->streamDownload(function () use ($rows, $showMoney): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_values(array_filter([__('Country'), __('Currency'), $showMoney ? __('GMV today') : null, __('Orders today'), __('Active sellers'), __('Pending approvals')])));

                foreach ($rows as $row) {
                    fputcsv($out, array_values(array_filter([$row['country']->name_en, $row['country']->currency, $showMoney ? $row['gmv'] : null, $row['orders'], $row['active_vendors'], $row['pending_approvals']], fn ($value): bool => $value !== null)));
                }

                fclose($out);
            }, 'nawy-overview-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('panels.admin.dashboard', [
            'panel' => $panel,
            'navigation' => PanelNavigation::for($panel, 'admin.dashboard'),
            'countries' => $countries,
            'rows' => $rows,
            'showMoney' => $showMoney,
            'failedJobs' => $request->user()->can(AdminPermission::SystemHealthView->value) ? app(SystemHealth::class)->failedJobs()['last_day'] : null,
            'adminNow' => $clock->inAdminTime(now()),
        ]);
    }
}
