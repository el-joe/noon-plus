<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Models\QuarantineEntry;
use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\MigrationJobs;
use App\Domains\Migration\Support\TableRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Data migration re-tries: a step for a country (or every country)
 * is previewed as a dry run on the `migration` queue, then applied — the
 * same parameters, for real — from the result page. Applying a single-row
 * preview runs in the request. Every start is activity-logged.
 */
class MigrationJobController extends Controller
{
    public function show(MigrationJob $job): View
    {
        return view('panels.admin.migration.job', [
            'job' => $job,
            'requester' => $job->requested_by ? User::query()->find($job->requested_by) : null,
            'entry' => $job->quarantine_id ? QuarantineEntry::query()->find($job->quarantine_id) : null,
            'preview' => $job->preview_id ? MigrationJob::query()->find($job->preview_id) : null,
            'applied' => MigrationJob::query()->where('preview_id', $job->id)->first(),
        ]);
    }

    public function store(Request $request, MigrationJobs $jobs): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', Rule::in(TableRules::STEPS)],
            'country' => ['nullable', Rule::in(MigrationContext::allCountries())],
        ]);

        $job = $jobs->previewStep($data['step'], $data['country'] ?? null, $request->user());
        activity('data_migration')->causedBy($request->user())->performedOn($job)->event('retry_previewed')
            ->withProperties(['job' => $job->id, 'step' => $job->step, 'country' => $job->country_code])
            ->log('Step re-try previewed (dry run queued)');

        return redirect()->route('admin.migration.jobs.show', $job)->with('status', __('Dry run queued on the migration queue. This page shows the result when it finishes.'));
    }

    public function apply(Request $request, MigrationJob $job, MigrationJobs $jobs): RedirectResponse
    {
        if (! $job->canApply()) {
            return back()->withErrors(['action' => __('Only a finished dry run that was not applied yet can be applied.')]);
        }

        $run = $jobs->apply($job, $request->user());
        activity('data_migration')->causedBy($request->user())->performedOn($run)->event('retry_applied')
            ->withProperties(['job' => $run->id, 'preview' => $job->id, 'kind' => $run->kind, 'step' => $run->step, 'country' => $run->country_code, 'source_table' => $run->source_table, 'source_id' => $run->source_id])
            ->log('Migration re-try applied');

        if ($run->kind === 'row' && $run->quarantine_id) {
            $entry = QuarantineEntry::query()->find($run->quarantine_id);

            return redirect()->route('admin.migration.quarantine.show', $run->quarantine_id)->with('status', match (true) {
                $run->status === 'failed' => __('The re-try failed; see the run below.'),
                $entry?->resolved_at !== null => __('The row was migrated and left the quarantine.'),
                default => __('The re-try ran; the row is still quarantined (see the reason).'),
            });
        }

        return redirect()->route('admin.migration.jobs.show', $run)->with('status', __('Run queued on the migration queue.'));
    }
}
