<?php

namespace App\Domains\Marketers\Http\Controllers\Studio;

use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Marketers\Support\CelebrityReports;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Creator Studio → Earnings → Monthly reports (FR-ANL-2): the workspace
 * marketer's own celebrity monthly reports, viewable and as PDF.
 */
class CelebrityReportController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace, private CelebrityReports $reports) {}

    public function index(): View
    {
        return view('panels.marketer.reports', [
            'reports' => CelebrityMonthlyReport::query()->where('marketer_profile_id', $this->workspace->marketer()->id)->orderByDesc('period')->paginate(12),
        ]);
    }

    public function show(int $report): View
    {
        return view('panels.marketer.report', ['report' => $this->own($report)]);
    }

    public function pdf(int $report): Response
    {
        $report = $this->own($report);

        return response($this->reports->pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->fileName().'"',
        ]);
    }

    private function own(int $id): CelebrityMonthlyReport
    {
        return CelebrityMonthlyReport::query()->where('marketer_profile_id', $this->workspace->marketer()->id)->with('marketer')->findOrFail($id);
    }
}
