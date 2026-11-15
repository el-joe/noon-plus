<x-layouts.panel :title="__('Shipments')" :breadcrumbs="[__('Operations'), __('Shipments')]">
    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :placeholder="__('All statuses')" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :selected="$filters['status'] ?? null" onchange="this.form.submit()" />
    </form>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Shipment'), __('Destination'), __('Driver'), __('Attempts'), __('Status')]" :rows="$shipments->isNotEmpty()" :empty="__('No shipments yet.')">
            @foreach ($shipments as $shipment)
                <tr class="border-t border-line" data-shipment="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium">{{ $shipment->number }}</td>
                    <td class="px-5 py-3 text-xs">{{ $shipment->subOrder->order->shipping_address['city'] ?? '—' }} · {{ $shipment->subOrder->order->shipping_address['area'] ?? '' }}</td>
                    <td class="px-5 py-3">{{ $shipment->assignments->first()?->agent?->name ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $shipment->attempts }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $shipments->links() }}
</x-layouts.panel>
