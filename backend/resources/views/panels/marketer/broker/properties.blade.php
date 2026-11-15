<x-layouts.panel :title="__('Properties')" :subtitle="__('Your property ads — post from the Nawy app or website as a broker')" :breadcrumbs="[__('Broker'), __('Properties')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Property'), __('Purpose'), __('Permit'), __('Price'), __('Inquiries'), __('Views'), __('Status')]" :rows="$ads->isNotEmpty()" :empty="__('No properties yet.')">
            @foreach ($ads as $ad)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $ad->title }}<span class="block text-xs text-fg-secondary">{{ $ad->number }} · {{ $ad->city }}</span></td>
                    <td class="px-5 py-3">{{ ucfirst(str_replace('_', ' ', $ad->property?->purpose ?? '—')) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $ad->property?->permit_number ?? '—' }}</td>
                    <td class="px-5 py-3">@if ($ad->price !== null)<x-ui.money :amount="$ad->price" :currency="$ad->currency" />@endif</td>
                    <td class="px-5 py-3">{{ $ad->threads_count }}</td>
                    <td class="px-5 py-3">{{ $ad->views }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$ad->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $ads->links() }}
</x-layouts.panel>
