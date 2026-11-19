{{-- Per-table metrics of one step (MigrationContext::metricsFor): counts and Σ per currency. --}}
<x-ui.table :headers="[__('Legacy table'), __('Read'), __('Written'), __('Updated'), __('Unchanged'), __('Archived'), __('Quarantined'), __('Other'), __('Σ per currency')]" :rows="count($tables) > 0" :empty="__('Nothing to do.')">
    @foreach ($tables as $table => $counts)
        @php($sums = $counts['sums'] ?? [])
        @php($main = ['read', 'written', 'updated', 'unchanged', 'archived', 'quarantined'])
        @php($other = collect($counts)->except([...$main, 'sums'])->map(fn ($value, $key) => $key.'='.$value)->implode(' · '))
        <tr class="border-t border-line align-top" data-metrics-table="{{ $table }}">
            <td class="px-5 py-2 font-mono text-xs">{{ $table }}</td>
            @foreach ($main as $metric)
                <td @class(['px-5 py-2 tabular-nums', 'font-semibold text-fg-danger' => $metric === 'quarantined' && ($counts[$metric] ?? 0) > 0])>{{ $counts[$metric] ?? 0 }}</td>
            @endforeach
            <td class="px-5 py-2 text-xs text-fg-secondary">{{ $other ?: '—' }}</td>
            <td class="px-5 py-2 font-mono text-xs">{{ collect($sums)->map(fn ($sum, $currency) => $currency.' '.$sum)->implode(' · ') ?: '—' }}</td>
        </tr>
    @endforeach
</x-ui.table>
