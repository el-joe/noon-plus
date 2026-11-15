<?php

namespace App\Domains\Classifieds\Http\Controllers\Admin;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Enums\ReportStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedReport;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedReports;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Classifieds review (FR-CLS-2, FR-CLS-6: attachments checked
 * during review) and the abuse report queue, in the selected country.
 */
class ClassifiedReviewController extends Controller
{
    public function __construct(private ClassifiedAdMachine $machine, private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.admin.classifieds.review', [
            'ads' => ClassifiedAd::query()->where('status', ClassifiedAdStatus::PendingReview)->with(['category', 'media', 'vendor', 'marketer', 'property'])->orderBy('submitted_at')->paginate(25),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function approve(Request $request, int $ad): RedirectResponse
    {
        $this->machine->approve(ClassifiedAd::query()->with('category')->findOrFail($ad), $request->user()->id);

        return back()->with('status', __('Ad approved and live.'));
    }

    public function reject(Request $request, int $ad): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $this->machine->reject(ClassifiedAd::query()->findOrFail($ad), $request->user()->id, $reason);

        return back()->with('status', __('Ad rejected; the seller sees your reason.'));
    }

    public function reports(): View
    {
        return view('panels.admin.classifieds.reports', [
            'reports' => ClassifiedReport::query()->whereIn('status', [ReportStatus::Open, ReportStatus::Reviewing])->with('ad')->oldest()->paginate(25),
        ]);
    }

    public function decide(Request $request, int $report, ClassifiedReports $reports): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:reviewing,actioned,dismissed'], 'note' => ['nullable', 'string', 'max:255']]);
        $reports->decide(ClassifiedReport::query()->with('ad.category')->findOrFail($report), ReportStatus::from($data['decision']), $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('Report handled.'));
    }
}
