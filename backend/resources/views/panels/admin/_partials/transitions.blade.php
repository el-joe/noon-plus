{{-- Admin pages show Oman time; a seller page passes its country's timezone (browser test BT-VEN-004). --}}
@php($zone = $timezone ?? config('nawy.admin_timezone'))
@php($actors = app(\App\Shared\Support\UserNames::class)->preload($transitions->pluck('actor_user_id')))
<x-ui.card :title="$title ?? __('History')" :subtitle="isset($timezone) ? __('Times in :zone', ['zone' => $timezone]) : __('Times in Oman')" flush>
    <x-ui.table :headers="[__('When'), __('From'), __('To'), __('By'), __('Reason')]" :rows="$transitions->isNotEmpty()" :empty="__('No history yet.')">
        @foreach ($transitions as $transition)
            <tr class="border-t border-line">
                <td class="px-5 py-3 text-xs">{{ $transition->created_at?->setTimezone($zone)->format('j M Y, H:i') }}</td>
                <td class="px-5 py-3">{{ $transition->from_status ? str_replace('_', ' ', $transition->from_status) : '—' }}</td>
                <td class="px-5 py-3"><x-ui.status-badge :status="$transition->to_status" /></td>
                <td class="px-5 py-3 text-xs">{{ ucfirst($transition->actor_type instanceof \BackedEnum ? $transition->actor_type->value : (string) $transition->actor_type) }}{{ $transition->actor_user_id ? ' · '.$actors->name($transition->actor_user_id) : '' }}</td>
                <td class="px-5 py-3 text-xs">{{ $transition->reason ?? '—' }}</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
