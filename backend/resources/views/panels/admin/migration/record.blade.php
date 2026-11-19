@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
@php($format = fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? 'NULL')))
<x-layouts.panel :title="__('Legacy :table row', ['table' => $record->source_table])" :subtitle="__('Legacy id :id · :database · rule :rule (:reason) · archived :at (Oman)', ['id' => $record->source_id, 'database' => $database === 'central' ? __('Central') : strtoupper($database), 'rule' => $record->rule, 'reason' => $record->reason, 'at' => $oman($record->archived_at)])" :breadcrumbs="[__('Data migration'), __('Legacy records'), $record->source_table]">
    <x-slot:actions>
        <x-ui.button variant="outline" size="sm" :href="route('admin.migration.records', ['database' => $database, 'table' => $record->source_table])">{{ __('Back to the records') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card :title="__('Legacy row')" :subtitle="$revealed ? __('Personal data shown — this view was logged. Passwords and tokens are never shown.') : __('Personal data is masked.')">
        <x-slot:actions>
            @can('data_migration.manage')
                @unless ($revealed)
                    <form method="POST" action="{{ route('admin.migration.records.reveal', [$database, $record->id]) }}" data-reveal>@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Reveal personal data') }}</x-ui.button></form>
                @endunless
            @endcan
        </x-slot:actions>
        <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[minmax(10rem,auto)_1fr]" data-payload>
            @foreach ($payload as $key => $value)
                <dt class="font-mono text-xs text-fg-secondary">{{ $key }}</dt>
                <dd class="break-all font-mono text-xs">{{ $format($value) }}</dd>
            @endforeach
        </dl>
    </x-ui.card>
</x-layouts.panel>
