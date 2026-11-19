@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i:s') : '—')
<x-layouts.panel :title="__('Run · :step', ['step' => $run->step])" :subtitle="__('Run :id · :countries · started :started, finished :finished (Oman)', ['id' => $run->run_id, 'countries' => $run->countries ? strtoupper($run->countries) : __('All countries'), 'started' => $oman($run->started_at), 'finished' => $oman($run->finished_at)])" :breadcrumbs="[__('Data migration'), __('Migration runs'), $run->step]">
    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Read')" :value="(string) ($run->totals['read'] ?? 0)" />
        <x-ui.stat-card :label="__('Written / archived')" :value="(string) (($run->totals['written'] ?? 0) + ($run->totals['archived'] ?? 0))" />
        <x-ui.stat-card :label="__('Unchanged')" :value="(string) ($run->totals['unchanged'] ?? 0)" />
        <x-ui.stat-card :label="__('Quarantined')" :value="(string) ($run->totals['quarantined'] ?? 0)" :trend="($run->totals['quarantined'] ?? 0) > 0 ? 'down' : 'up'" />
    </section>

    @if ($run->scope)
        <p class="text-sm text-fg-secondary">{{ __('Single-row re-try: :scope', ['scope' => $run->scope]) }}</p>
    @endif

    <x-ui.card :title="__('Tables')" flush>
        @include('panels.admin.migration._metrics', ['tables' => $run->tables])
    </x-ui.card>

    @if ($quarantined > 0)
        <p class="text-sm"><a class="text-fg-link" href="{{ route('admin.migration.quarantine', ['step' => $run->step, 'status' => 'all']) }}">{{ trans_choice(':count row of this step is still in the quarantine from this run|:count rows of this step are still in the quarantine from this run', $quarantined) }}</a></p>
    @endif

    <x-ui.card :title="__('Steps of this run')" flush>
        <x-ui.table :headers="[__('Step'), __('Read'), __('Written'), __('Quarantined'), __('Finished (Oman)')]">
            @foreach ($siblings as $sibling)
                <tr @class(['border-t border-line', 'bg-subtle' => $sibling->id === $run->id])>
                    <td class="px-5 py-2"><a class="text-fg-link" href="{{ route('admin.migration.runs.show', $sibling->id) }}">{{ $sibling->step }}</a></td>
                    <td class="px-5 py-2 tabular-nums">{{ $sibling->totals['read'] ?? 0 }}</td>
                    <td class="px-5 py-2 tabular-nums">{{ ($sibling->totals['written'] ?? 0) + ($sibling->totals['archived'] ?? 0) }}</td>
                    <td class="px-5 py-2 tabular-nums">{{ $sibling->totals['quarantined'] ?? 0 }}</td>
                    <td class="px-5 py-2 text-xs">{{ $oman($sibling->finished_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
