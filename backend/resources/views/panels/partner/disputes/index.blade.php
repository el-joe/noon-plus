<x-layouts.panel :title="__('Disputes')" :subtitle="__('Customer disputes about your shipments')" :breadcrumbs="[__('Sales'), __('Disputes')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Dispute'), __('Shipment'), __('Reason'), __('Opened'), __('Status')]" :rows="$disputes->isNotEmpty()" :empty="__('No disputes.')">
            @foreach ($disputes as $dispute)
                <tr class="border-t border-line" data-dispute="{{ $dispute->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('partner.disputes.show', $dispute->id) }}">{{ $dispute->number }}</a></td>
                    <td class="px-5 py-3">{{ $dispute->subOrder?->number }}</td>
                    <td class="px-5 py-3">{{ str_replace('_', ' ', $dispute->reason->value) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $dispute->created_at->setTimezone($country->timezone)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$dispute->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $disputes->links() }}
</x-layouts.panel>
