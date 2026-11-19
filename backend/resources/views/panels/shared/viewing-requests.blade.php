{{-- Owner decision D12: viewing / reserve requests are records only. No status, no actions: the advertiser contacts the customer outside Nawy. --}}
@php($timezone = app(\App\Domains\Countries\Support\CountryContext::class)->current()->timezone)
<x-layouts.panel :title="__('Viewing & reserve requests')" :subtitle="__('Contact the customer directly by phone or email. Nawy keeps these requests as records only.')" :breadcrumbs="$breadcrumbs">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Request type') }}">
        @foreach (['' => __('All'), 'viewing' => __('Viewing'), 'reserve' => __('Reserve')] as $value => $label)
            <a href="{{ request()->url().($value === '' ? '' : '?type='.$value) }}" @class(['rounded-full border px-3 py-1 text-sm', 'border-brand bg-brand-subtle font-semibold' => (string) $type === $value, 'border-line' => (string) $type !== $value])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Ad'), __('Type'), __('Customer'), __('Preferred time'), __('Message'), __('Received')]" :rows="$requests->isNotEmpty()" :empty="__('No requests yet.')">
            @foreach ($requests as $viewing)
                <tr class="border-t border-line align-top" data-viewing-request="{{ $viewing->id }}">
                    <td class="px-5 py-3">{{ $viewing->ad?->title }}<span class="block text-xs text-fg-secondary">{{ $viewing->ad?->number }}</span></td>
                    <td class="px-5 py-3">{{ $viewing->type === 'reserve' ? __('Reserve') : __('Viewing') }}</td>
                    <td class="px-5 py-3">
                        {{ $viewing->contact_name }}
                        <a class="block text-xs text-fg-link" href="mailto:{{ $viewing->contact_email }}">{{ $viewing->contact_email }}</a>
                        <a class="block text-xs text-fg-link" href="tel:{{ $viewing->contact_phone }}" dir="ltr">{{ $viewing->contact_phone }}</a>
                    </td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $viewing->preferred_at->setTimezone($timezone)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3 text-sm">{{ $viewing->note ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $viewing->created_at?->setTimezone($timezone)->format('j M Y, H:i') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $requests->links() }}
</x-layouts.panel>
