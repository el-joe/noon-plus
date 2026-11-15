<x-layouts.panel :title="__('Activity log')" :subtitle="__('Who changed what in :country and on the platform · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Overview'), __('Activity log')]">
    <form method="GET" action="{{ route('admin.activity') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4 lg:grid-cols-7" role="search">
        <x-ui.input :label="__('User')" name="user" :value="$filters['user'] ?? ''" :placeholder="__('Name or email')" />
        <x-ui.select :label="__('Log')" name="log" :selected="$filters['log'] ?? ''" :placeholder="__('All')" :options="$logs->mapWithKeys(fn ($log) => [$log => $log])->all()" />
        <x-ui.select :label="__('Subject')" name="subject" :selected="$filters['subject'] ?? ''" :placeholder="__('All')" :options="$subjects->mapWithKeys(fn ($subject) => [$subject => \Illuminate\Support\Str::headline($subject)])->all()" />
        <x-ui.select :label="__('Scope')" name="scope" :selected="$filters['scope'] ?? ''" :placeholder="__('Country + platform')" :options="['country' => $country->name_en, 'central' => __('Platform (central)')]" />
        <x-ui.input :label="__('From (Oman)')" name="from" type="date" :value="$filters['from'] ?? ''" />
        <x-ui.input :label="__('To (Oman)')" name="to" type="date" :value="$filters['to'] ?? ''" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('When (Oman)'), __('User'), __('Log'), __('Event'), __('Subject'), __('Changes')]" :rows="$entries->isNotEmpty()" :empty="__('No activity matches.')">
            @foreach ($entries as $entry)
                @php($changes = collect($entry->properties->get('attributes', []))->map(fn ($value, $key) => $key.': '.\Illuminate\Support\Str::limit(is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value), 60)))
                <tr class="border-t border-line align-top" data-activity="{{ $entry->id }}">
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $entry->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i:s') }}</td>
                    <td class="px-5 py-3">{{ $causers->get($entry->causer_id)?->name ?? __('System') }}<span class="block text-xs text-fg-secondary">{{ $causers->get($entry->causer_id)?->email }}</span></td>
                    <td class="px-5 py-3">{{ $entry->log_name }}@if ($entry->properties->get('country'))<span class="block text-xs uppercase text-fg-secondary">{{ $entry->properties->get('country') }}</span>@endif</td>
                    <td class="px-5 py-3">{{ $entry->event ?? '—' }}<span class="block text-xs text-fg-secondary">{{ $entry->description }}</span></td>
                    <td class="px-5 py-3">{{ $short($entry->subject_type) }}{{ $entry->subject_id ? ' #'.$entry->subject_id : '' }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $changes->take(6)->implode(' · ') ?: '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $entries->links() }}
</x-layouts.panel>
