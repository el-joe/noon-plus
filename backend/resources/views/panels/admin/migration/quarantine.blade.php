@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
@php($statusLabels = ['open' => __('Open'), 'resolved' => __('Marked resolved'), 'auto_resolved' => __('Migrated by a later run'), 'waived' => __('Waived'), 'all' => __('All')])
@php($statusTones = ['open' => 'warning', 'resolved' => 'info', 'auto_resolved' => 'success', 'waived' => 'neutral'])
<x-layouts.panel :title="__('Quarantine queue')" :subtitle="__('Legacy rows the migration could not place, with the reason. Decide, fix at the source and re-try · times in Oman')" :breadcrumbs="[__('Data migration'), __('Quarantine queue')]">
    <section class="grid gap-4 sm:grid-cols-4">
        @foreach (['open', 'resolved', 'waived', 'auto_resolved'] as $name)
            <a href="{{ route('admin.migration.quarantine', ['status' => $name]) }}" class="block" data-quarantine-count="{{ $name }}">
                <x-ui.stat-card :label="$statusLabels[$name]" :value="(string) $counts[$name]" :trend="$name === 'open' && $counts[$name] > 0 ? 'down' : 'up'" />
            </a>
        @endforeach
    </section>

    <form method="GET" action="{{ route('admin.migration.quarantine') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3 lg:grid-cols-7" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status']" :options="$statusLabels" />
        <x-ui.select :label="__('Step')" name="step" :selected="$filters['step'] ?? ''" :placeholder="__('All')" :options="collect($steps)->mapWithKeys(fn ($step) => [$step => $step])->all()" />
        <x-ui.select :label="__('Legacy table')" name="table" :selected="$filters['table'] ?? ''" :placeholder="__('All')" :options="$tables->mapWithKeys(fn ($table) => [$table => $table])->all()" />
        <x-ui.select :label="__('Country')" name="country" :selected="$filters['country'] ?? ''" :placeholder="__('All')" :options="collect($countries)->mapWithKeys(fn ($code) => [$code => strtoupper($code)])->all()" />
        <x-ui.select :label="__('Reason')" name="reason" :selected="$filters['reason'] ?? ''" :placeholder="__('All')" :options="$reasons->mapWithKeys(fn ($reason) => [$reason => $reason])->all()" />
        <x-ui.input :label="__('Legacy id')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Part of the id')" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Legacy row'), __('Step'), __('Country'), __('Reason'), __('Detail'), __('Status'), __('Updated (Oman)')]" :rows="$entries->isNotEmpty()" :empty="__('Nothing in the quarantine matches.')">
            @foreach ($entries as $entry)
                <tr class="border-t border-line align-top" data-quarantine="{{ $entry->id }}">
                    <td class="px-5 py-3"><a class="font-mono text-xs text-fg-link" href="{{ route('admin.migration.quarantine.show', $entry) }}">{{ $entry->source_table }} · {{ \Illuminate\Support\Str::limit($entry->source_id, 13) }}</a></td>
                    <td class="px-5 py-3">{{ $entry->step }}</td>
                    <td class="px-5 py-3 text-xs uppercase">{{ $entry->country_code ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.badge tone="danger">{{ $entry->reason }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs">{{ \Illuminate\Support\Str::limit(\App\Domains\Migration\Support\Reconciler::mask((string) $entry->detail), 90) }}</td>
                    <td class="px-5 py-3"><x-ui.badge :tone="$statusTones[$entry->status()]">{{ $statusLabels[$entry->status()] }}</x-ui.badge></td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $oman($entry->updated_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $entries->links() }}
</x-layouts.panel>
