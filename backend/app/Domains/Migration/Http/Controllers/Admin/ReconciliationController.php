<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Support\MigrationJobs;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin → Data migration → Reconciliation: the latest report written by
 * `legacy:reconcile` (summary + the markdown rendered, personal data already
 * masked by the command) and "Run reconcile" (queued on the migration queue).
 */
class ReconciliationController extends Controller
{
    public function show(): View
    {
        $path = self::reportPath();
        $markdown = File::exists($path) ? File::get($path) : null;

        return view('panels.admin.migration.reconciliation', [
            'html' => $markdown === null ? null : Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'summary' => $markdown === null ? null : self::summary($markdown),
            'writtenAt' => $markdown === null ? null : File::lastModified($path),
            'jobs' => MigrationJob::query()->where('kind', 'reconcile')->latest('id')->limit(5)->get(),
            'quarantine' => [
                'open' => DB::table('migration_quarantine')->whereNull('resolved_at')->whereNull('review_status')->count(),
                'resolved' => DB::table('migration_quarantine')->whereNull('resolved_at')->where('review_status', 'resolved')->count(),
                'waived' => DB::table('migration_quarantine')->whereNull('resolved_at')->where('review_status', 'waived')->count(),
            ],
            'lastRun' => DB::table('migration_runs')->latest('id')->first(),
        ]);
    }

    public function run(Request $request, MigrationJobs $jobs): RedirectResponse
    {
        if (MigrationJob::query()->where('kind', 'reconcile')->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->withErrors(['action' => __('A reconciliation is already queued or running.')]);
        }

        $job = $jobs->queue('reconcile', $request->user());
        activity('data_migration')->causedBy($request->user())->performedOn($job)->event('reconcile_queued')->log('Reconciliation queued');

        return redirect()->route('admin.migration.reconciliation')->with('status', __('Reconciliation queued on the migration queue. The report below is replaced when it finishes.'));
    }

    public static function reportPath(): string
    {
        $path = (string) config('nawy.legacy.reconcile_report');

        return str_starts_with($path, '/') ? $path : dirname(base_path()).'/'.$path;
    }

    /**
     * The "Result" table of the report: clean or not, and its row totals.
     *
     * @return array{clean: bool, differences: array<int, string>, totals: array<string, string>}
     */
    public static function summary(string $markdown): array
    {
        $result = Str::between($markdown, "## Result\n", "\n## ");
        preg_match_all('/^- (.+)$/m', $result, $differences);
        preg_match_all('/^\| (.+?) \| (\d+) \|$/m', $result, $totals, PREG_SET_ORDER);

        return [
            'clean' => str_contains($result, '**Clean:**'),
            'differences' => $differences[1] ?? [],
            'totals' => collect($totals)->mapWithKeys(fn (array $match): array => [trim($match[1]) => $match[2]])->all(),
        ];
    }
}
