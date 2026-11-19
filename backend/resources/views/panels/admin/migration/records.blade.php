@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
<x-layouts.panel :title="__('Legacy records')" :subtitle="__('Old-platform rows archived as they were (read only), per database')" :breadcrumbs="[__('Data migration'), __('Legacy records')]">
    <form method="GET" action="{{ route('admin.migration.records') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Database')" name="database" :selected="$filters['database']" :options="collect($databases)->mapWithKeys(fn ($db) => [$db => $db === 'central' ? __('Central') : strtoupper($db)])->all()" />
        <x-ui.select :label="__('Legacy table')" name="table" :selected="$filters['table'] ?? ''" :placeholder="__('All')" :options="$tables->mapWithKeys(fn ($table) => [$table => $table])->all()" />
        <x-ui.input :label="__('Legacy id')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Part of the id')" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Search') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Legacy table'), __('Legacy id'), __('Rule'), __('Reason'), __('Archived (Oman)')]" :rows="$records->isNotEmpty()" :empty="__('No archived row matches.')">
            @foreach ($records as $record)
                <tr class="border-t border-line" data-record="{{ $record->id }}">
                    <td class="px-5 py-2 font-mono text-xs">{{ $record->source_table }}</td>
                    <td class="px-5 py-2 font-mono text-xs"><a class="text-fg-link" href="{{ route('admin.migration.records.show', [$filters['database'], $record->id]) }}">{{ $record->source_id }}</a></td>
                    <td class="px-5 py-2">{{ $record->rule }}</td>
                    <td class="px-5 py-2">{{ $record->reason }}</td>
                    <td class="px-5 py-2 text-xs">{{ $oman($record->archived_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $records->links() }}
</x-layouts.panel>
