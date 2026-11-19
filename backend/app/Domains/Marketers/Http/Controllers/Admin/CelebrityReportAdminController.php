<?php

namespace App\Domains\Marketers\Http\Controllers\Admin;

use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\CelebrityReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Admin → Growth → Celebrity monthly report (FR-ANL-2, SRS §11.13): the
 * country's influencer reports by month (sorted by earnings), each one's
 * detail and PDF. Regenerating a month replaces its rows (no new email).
 */
class CelebrityReportAdminController extends Controller
{
    public function __construct(private CelebrityReports $reports) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $periods = CelebrityMonthlyReport::query()->distinct()->orderByDesc('period')->pluck('period');
        $period = $filters['period'] ?? $periods->first() ?? $this->reports->previousPeriod();

        return view('panels.admin.marketers.celebrity-reports', [
            'period' => $period,
            'periods' => $periods->push($this->reports->previousPeriod())->unique()->sortDesc()->values(),
            'reports' => CelebrityMonthlyReport::query()->where('period', $period)->with('marketer')->orderByDesc('total_earnings')->paginate(25)->withQueryString(),
        ]);
    }

    public function show(int $report): View
    {
        return view('panels.admin.marketers.celebrity-report', ['report' => CelebrityMonthlyReport::query()->with('marketer')->findOrFail($report)]);
    }

    public function pdf(int $report): Response
    {
        $report = CelebrityMonthlyReport::query()->with('marketer')->findOrFail($report);

        return response($this->reports->pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->fileName().'"',
        ]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m', function (string $attribute, string $value, \Closure $fail): void {
                if ($value > now()->format('Y-m')) {
                    $fail(__('The month cannot be in the future.'));
                }
            }],
            'marketer_profile_id' => ['nullable', 'integer'],
        ]);

        if (isset($data['marketer_profile_id'])) {
            $this->reports->generate(MarketerProfile::query()->findOrFail($data['marketer_profile_id']), $data['period'], $request->user()->id);
            $count = 1;
        } else {
            $count = $this->reports->generateAll($data['period'], email: false, userId: $request->user()->id);
        }

        return redirect()->route('admin.marketers.celebrity-reports', ['period' => $data['period']])
            ->with('status', trans_choice(':count report generated.|:count reports generated.', $count));
    }
}
