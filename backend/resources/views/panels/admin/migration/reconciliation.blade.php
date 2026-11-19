@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
@php($running = $jobs->first(fn ($job) => ! $job->isFinished()))
<x-layouts.panel :title="__('Reconciliation')" :subtitle="__('The latest legacy:reconcile report: every legacy row accounted for, money per currency, ledger, wallets, statuses, identity, signatures and files')" :breadcrumbs="[__('Data migration'), __('Reconciliation')]">
    <x-slot:actions>
        @can('data_migration.manage')
            <form method="POST" action="{{ route('admin.migration.reconciliation.run') }}" data-run-reconcile>@csrf<x-ui.button type="submit" size="sm" :disabled="(bool) $running">{{ __('Run reconcile') }}</x-ui.button></form>
        @endcan
    </x-slot:actions>

    @if ($running)
        <meta http-equiv="refresh" content="10">
        <p class="text-sm text-fg-secondary" data-reconcile-pending>{{ __('Reconciliation #:id is :status; this page refreshes every 10 seconds.', ['id' => $running->id, 'status' => $running->status]) }}</p>
    @endif

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Result')" :value="$summary === null ? __('No report yet') : ($summary['clean'] ? __('Clean') : trans_choice(':count difference|:count differences', count($summary['differences'])))" :trend="$summary && $summary['clean'] ? 'up' : 'down'" data-reconcile-result />
        <x-ui.stat-card :label="__('Report written (Oman)')" :value="$writtenAt ? $oman(\Illuminate\Support\Carbon::createFromTimestamp($writtenAt)) : '—'" />
        <x-ui.stat-card :label="__('Open quarantine')" :value="(string) $quarantine['open']" :delta="__(':resolved marked resolved · :waived waived', $quarantine)" />
        <x-ui.stat-card :label="__('Last migration step')" :value="$lastRun ? $lastRun->step : '—'" :delta="$lastRun ? $oman($lastRun->finished_at) : null" />
    </section>

    @if ($summary && $summary['totals'])
        <x-ui.card :title="__('Summary')" flush>
            <x-ui.table :headers="['', __('Rows')]">
                @foreach ($summary['totals'] as $label => $value)
                    <tr class="border-t border-line"><td class="px-5 py-2">{{ $label }}</td><td class="px-5 py-2 tabular-nums">{{ $value }}</td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($jobs->isNotEmpty())
        <x-ui.card :title="__('Recent runs from the panel')" flush>
            <x-ui.table :headers="[__('Run'), __('Status'), __('Exit code'), __('Queued (Oman)'), __('Finished (Oman)')]">
                @foreach ($jobs as $job)
                    <tr class="border-t border-line" data-reconcile-job="{{ $job->id }}">
                        <td class="px-5 py-2"><a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $job->id) }}">#{{ $job->id }}</a></td>
                        <td class="px-5 py-2">@include('panels.admin.migration._job-status', ['job' => $job])</td>
                        <td class="px-5 py-2">{{ $job->metrics['exit_code'] ?? '—' }}</td>
                        <td class="px-5 py-2 text-xs">{{ $oman($job->created_at) }}</td>
                        <td class="px-5 py-2 text-xs">{{ $oman($job->finished_at) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Report')">
        @if ($html)
            <article class="md-report overflow-x-auto text-sm [&_h1]:mb-3 [&_h1]:text-xl [&_h1]:font-bold [&_h2]:mt-6 [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_p]:my-2 [&_ul]:my-2 [&_ul]:list-disc [&_ul]:ps-6 [&_code]:rounded [&_code]:bg-subtle [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_table]:my-3 [&_table]:w-full [&_table]:text-xs [&_th]:border [&_th]:border-line [&_th]:bg-subtle [&_th]:px-2 [&_th]:py-1 [&_th]:text-start [&_td]:border [&_td]:border-line [&_td]:px-2 [&_td]:py-1" dir="ltr" data-report>{!! $html !!}</article>
        @else
            <p class="text-sm text-fg-secondary">{{ __('No report yet. Run reconcile to write it.') }}</p>
        @endif
    </x-ui.card>
</x-layouts.panel>
