<x-layouts.panel :title="__('Receiving')" :subtitle="__('Inbound shipments from sellers: arrived first, then on the way')" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Receiving')]">
    @include('panels.admin.inventory._nav')

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? null" :placeholder="__('Open shipments')"
                     :options="collect(\App\Domains\Fulfilment\Enums\InboundStatus::cases())->mapWithKeys(fn ($case) => [$case->value => \App\Shared\Localization\Label::of($case)])->all()" />
        <x-ui.select :label="__('Warehouse')" name="warehouse_id" :selected="$filters['warehouse_id'] ?? null" :placeholder="__('All warehouses')" :options="$warehouses->pluck('code', 'id')->all()" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Reference'), __('Store'), __('Warehouse'), __('Units'), __('Cartons'), __('Expected'), __('Tracking'), __('Status')]" :rows="$shipments->isNotEmpty()" :empty="__('Nothing to receive.')">
            @foreach ($shipments as $shipment)
                <tr class="border-t border-line" data-inbound="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-brand underline" href="{{ route('admin.inventory.inbound.show', $shipment->id) }}">{{ $shipment->reference() }}</a></td>
                    <td class="px-5 py-3">{{ $shipment->vendor->store_name }}</td>
                    <td class="px-5 py-3">{{ $shipment->warehouse->code }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ (int) $shipment->lines_sum_quantity_expected }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $shipment->cartons }}</td>
                    <td class="px-5 py-3">{{ $shipment->expected_at?->toDateString() ?? '—' }}</td>
                    <td class="px-5 py-3">{{ trim(($shipment->carrier_name ?? '').' '.($shipment->tracking_number ?? '')) ?: '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $shipments->links() }}
</x-layouts.panel>
