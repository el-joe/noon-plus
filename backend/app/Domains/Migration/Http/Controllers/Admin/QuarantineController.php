<?php

namespace App\Domains\Migration\Http\Controllers\Admin;

use App\Domains\Migration\Models\MigrationJob;
use App\Domains\Migration\Models\QuarantineEntry;
use App\Domains\Migration\Support\MigrationContext;
use App\Domains\Migration\Support\MigrationJobs;
use App\Domains\Migration\Support\PiiMask;
use App\Domains\Migration\Support\TableRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Data migration → Quarantine (plan §2.7 / §13.3): the rows the
 * migration parked with a reason. Filter by step, table, country, reason and
 * status; the detail shows the legacy row (personal data masked; "reveal" is
 * activity-logged), the reason and the related ids. Decisions — resolved or
 * waived, with a note — are activity-logged with the actor; a re-try runs the
 * row's step for that one row, dry run first (MigrationJobs).
 */
class QuarantineController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['step', 'table', 'country', 'reason', 'status', 'q']);
        $status = $filters['status'] ?? 'open';
        $entries = QuarantineEntry::query()
            ->when($filters['step'] ?? null, fn ($query, $step) => $query->where('step', $step))
            ->when($filters['table'] ?? null, fn ($query, $table) => $query->where('source_table', $table))
            ->when($filters['country'] ?? null, fn ($query, $country) => $query->where('country_code', $country))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($filters['q'] ?? null, fn ($query, $id) => $query->where('source_id', 'like', LikeSearch::contains((string) $id)))
            ->when($status !== 'all', fn ($query) => $query->withStatus($status))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('panels.admin.migration.quarantine', [
            'entries' => $entries,
            'filters' => [...$filters, 'status' => $status],
            'steps' => TableRules::STEPS,
            'tables' => DB::table('migration_quarantine')->distinct()->orderBy('source_table')->pluck('source_table'),
            'reasons' => DB::table('migration_quarantine')->distinct()->orderBy('reason')->pluck('reason'),
            'countries' => MigrationContext::allCountries(),
            'counts' => collect(QuarantineEntry::STATUSES)->mapWithKeys(fn (string $name): array => [$name => QuarantineEntry::query()->withStatus($name)->count()]),
        ]);
    }

    public function show(Request $request, QuarantineEntry $entry): View
    {
        return $this->detail($request, $entry, false);
    }

    /**
     * Show the legacy row unmasked (secrets stay hidden); logged.
     */
    public function reveal(Request $request, QuarantineEntry $entry): View
    {
        activity('data_migration')->causedBy($request->user())->performedOn($entry)->event('pii_revealed')
            ->withProperties(['source_table' => $entry->source_table, 'source_id' => $entry->source_id])
            ->log('Quarantined legacy row revealed');

        return $this->detail($request, $entry, true);
    }

    public function review(Request $request, QuarantineEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['resolved', 'waived', 'reopen'])],
            'note' => ['required_unless:decision,reopen', 'nullable', 'string', 'max:1000'],
        ]);

        if ($entry->resolved_at !== null) {
            return back()->withErrors(['action' => __('This row was migrated by a later run; nothing to decide.')]);
        }

        $before = $entry->review_status;
        $entry->forceFill($data['decision'] === 'reopen'
            ? ['review_status' => null, 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]
            : ['review_status' => $data['decision'], 'review_note' => $data['note'], 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now()])->save();

        activity('data_migration')->causedBy($request->user())->performedOn($entry)->event('quarantine_'.$data['decision'])
            ->withProperties(['source_table' => $entry->source_table, 'source_id' => $entry->source_id, 'reason' => $entry->reason, 'from' => $before, 'to' => $entry->review_status, 'note' => $entry->review_note])
            ->log(match ($data['decision']) {
                'resolved' => 'Quarantine entry marked resolved',
                'waived' => 'Quarantine entry waived',
                default => 'Quarantine entry reopened',
            });

        return redirect()->route('admin.migration.quarantine.show', $entry)->with('status', match ($data['decision']) {
            'resolved' => __('Marked resolved. The next run of its step clears it from the quarantine, or puts it back in the queue if it still fails.'),
            'waived' => __('Waived: the row stays unmigrated and is reported as quarantined with your note.'),
            default => __('Back in the open queue.'),
        });
    }

    /**
     * Dry run of the row's step for this one row (runs in the request).
     */
    public function preview(Request $request, QuarantineEntry $entry, MigrationJobs $jobs): RedirectResponse
    {
        if ($entry->resolved_at !== null) {
            return back()->withErrors(['action' => __('This row was migrated by a later run; nothing to re-try.')]);
        }

        $job = $jobs->previewRow($entry, $request->user());
        activity('data_migration')->causedBy($request->user())->performedOn($entry)->event('retry_previewed')
            ->withProperties(['job' => $job->id, 'step' => $entry->step, 'source_table' => $entry->source_table, 'source_id' => $entry->source_id])
            ->log('Quarantined row re-try previewed (dry run)');

        return redirect()->route('admin.migration.quarantine.show', $entry)->with('status', __('Dry run finished: nothing was written. Review the result, then apply it.'));
    }

    private function detail(Request $request, QuarantineEntry $entry, bool $reveal): View
    {
        $payload = $entry->payload ?? [];

        return view('panels.admin.migration.quarantine-show', [
            'entry' => $entry,
            'payload' => PiiMask::row($payload, $reveal),
            'revealed' => $reveal,
            'reviewer' => $entry->reviewed_by ? User::query()->find($entry->reviewed_by) : null,
            'related' => $this->related($entry, $payload),
            'targets' => DB::table('migration_id_map')->where('source_table', $entry->source_table)->where('source_id', $entry->source_id)->get(),
            'rule' => TableRules::for($entry->source_table),
            'jobs' => MigrationJob::query()->where('quarantine_id', $entry->id)->orderByDesc('id')->limit(10)->get(),
            'history' => DB::table('activity_log')->where('log_name', 'data_migration')->where('subject_type', $entry->getMorphClass())->where('subject_id', $entry->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /**
     * Every `*_id` of the legacy row: where it went (id map) or whether it is quarantined too.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{column: string, id: string, targets: Collection<int, object>, quarantined: Collection<int, object>}>
     */
    private function related(QuarantineEntry $entry, array $payload): array
    {
        $out = [];

        foreach ($payload as $column => $value) {
            if (! str_ends_with((string) $column, '_id') || ! is_scalar($value) || $value === '' || mb_strlen((string) $value) > 64) {
                continue;
            }

            $id = (string) $value;
            $out[] = [
                'column' => (string) $column,
                'id' => $id,
                'targets' => DB::table('migration_id_map')->where('source_id', $id)->limit(10)->get(),
                'quarantined' => DB::table('migration_quarantine')->where('source_id', $id)->where('id', '!=', $entry->id)->limit(5)->get(),
            ];
        }

        return $out;
    }
}
