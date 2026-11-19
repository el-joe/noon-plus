@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i:s') : '—')
@php($kinds = ['row' => __('Single-row re-try'), 'step' => __('Step re-try'), 'reconcile' => __('Reconciliation'), 'redirects' => __('Redirect map rebuild')])
<x-layouts.panel :title="__('Migration task #:id', ['id' => $job->id])" :subtitle="$kinds[$job->kind].($job->step ? ' · '.$job->step : '').($job->kind === 'step' ? ' · '.($job->country_code ? strtoupper($job->country_code) : __('All countries')) : '').($job->source_table ? ' · '.$job->source_table.' '.$job->source_id : '')" :breadcrumbs="[__('Data migration'), __('Migration runs'), '#'.$job->id]">
    @unless ($job->isFinished())
        <meta http-equiv="refresh" content="5">
    @endunless

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.card :title="__('Status')"><div class="flex flex-wrap gap-2">@include('panels.admin.migration._job-status', ['job' => $job])</div></x-ui.card>
        <x-ui.stat-card :label="__('Requested by')" :value="$requester?->name ?? __('System')" />
        <x-ui.stat-card :label="__('Started (Oman)')" :value="$oman($job->started_at)" />
        <x-ui.stat-card :label="__('Finished (Oman)')" :value="$oman($job->finished_at)" />
    </section>

    @unless ($job->isFinished())
        <p class="text-sm text-fg-secondary" data-job-pending>{{ __('Waiting for the migration queue; this page refreshes every 5 seconds.') }}</p>
    @endunless

    @if ($job->dry_run && $job->status === 'done')
        <x-ui.card :title="__('Dry run result')" :subtitle="__('Nothing was written. Apply runs exactly this step and scope for real.')">
            @if ($applied)
                <p class="text-sm">{{ __('Applied as') }} <a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $applied->id) }}">#{{ $applied->id }}</a> ({{ $applied->status }}).</p>
            @elseif ($job->canApply())
                @can('data_migration.manage')
                    <form method="POST" action="{{ route('admin.migration.jobs.apply', $job->id) }}" data-apply>@csrf<x-ui.button type="submit">{{ __('Apply') }}</x-ui.button></form>
                @endcan
            @endif
        </x-ui.card>
    @endif

    @if ($preview)
        <p class="text-sm">{{ __('Applies the dry run') }} <a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $preview->id) }}">#{{ $preview->id }}</a>.</p>
    @endif
    @if ($entry)
        <p class="text-sm">{{ __('Quarantine entry') }} <a class="text-fg-link" href="{{ route('admin.migration.quarantine.show', $entry) }}">{{ $entry->source_table }} · {{ $entry->source_id }}</a></p>
    @endif

    @if (in_array($job->kind, ['row', 'step'], true) && $job->status === 'done')
        <x-ui.card :title="__('Tables')" flush>
            @include('panels.admin.migration._metrics', ['tables' => $job->metrics['tables'] ?? []])
        </x-ui.card>
    @endif

    @if ($job->output)
        <x-ui.card :title="$job->status === 'failed' ? __('Error') : __('Output')">
            @if (isset($job->metrics['exit_code']))
                <p class="mb-2 text-sm">{{ __('Exit code :code', ['code' => $job->metrics['exit_code']]) }}</p>
            @endif
            <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-subtle p-3 font-mono text-xs" data-job-output>{{ $job->output }}</pre>
        </x-ui.card>
    @endif
</x-layouts.panel>
