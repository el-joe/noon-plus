<x-layouts.panel :title="__('Inbound :number', ['number' => $shipment->reference()])" :subtitle="__('To :warehouse', ['warehouse' => $shipment->warehouse->code.' · '.$shipment->warehouse->name()])" :breadcrumbs="[__('Inventory & warehouses'), __('Inbound plans'), $shipment->reference()]">
    <x-slot:actions>
        @if ($shipment->status->hasLabels())
            <x-ui.button variant="outline" :href="route('partner.inventory.inbound.labels', $shipment->id)" target="_blank">{{ __('Print labels') }}</x-ui.button>
            <x-ui.button variant="outline" :href="route('partner.inventory.inbound.labels-pdf', $shipment->id)">{{ __('Labels (PDF)') }}</x-ui.button>
        @endif
        @if ($shipment->status === \App\Domains\Fulfilment\Enums\InboundStatus::Draft)
            <form method="POST" action="{{ route('partner.inventory.inbound.act', [$shipment->id, 'submit']) }}">@csrf<x-ui.button type="submit">{{ __('Submit plan') }}</x-ui.button></form>
        @endif
    </x-slot:actions>

    @include('panels.partner.inventory._nav')

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Products')" flush>
                <x-ui.table :headers="[__('Product'), __('SKU'), __('Expected'), __('Cartons'), __('Received'), __('Damaged'), __('Short')]">
                    @foreach ($shipment->lines as $line)
                        <tr class="border-t border-line">
                            <td class="px-5 py-3 font-medium">{{ $line->listing->variant->product->title() }}</td>
                            <td class="px-5 py-3">{{ $line->listing->variant->sku }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $line->quantity_expected }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $line->cartons }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $line->quantity_received }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $line->quantity_damaged }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ in_array($shipment->status->value, ['received', 'closed'], true) ? $line->shortQuantity() : '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @if ($shipment->status === \App\Domains\Fulfilment\Enums\InboundStatus::Submitted)
                <x-ui.card :title="__('Shipped it?')" :subtitle="__('Add the carrier and tracking number so the warehouse can expect it.')">
                    <form method="POST" action="{{ route('partner.inventory.inbound.act', [$shipment->id, 'ship']) }}" class="flex flex-wrap items-end gap-3 px-5 pb-5">
                        @csrf
                        <x-ui.input :label="__('Carrier')" name="carrier_name" required />
                        <x-ui.input :label="__('Tracking number')" name="tracking_number" />
                        <x-ui.button type="submit">{{ __('Mark as shipped') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Status')">
                <dl class="grid grid-cols-2 gap-2 px-5 pb-5 text-sm">
                    <dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$shipment->status" /></dd>
                    <dt class="text-fg-secondary">{{ __('Cartons') }}</dt><dd>{{ $shipment->cartons }}</dd>
                    <dt class="text-fg-secondary">{{ __('Expected arrival') }}</dt><dd>{{ $shipment->expected_at?->toDateString() ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Carrier') }}</dt><dd>{{ $shipment->carrier_name ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Tracking number') }}</dt><dd>{{ $shipment->tracking_number ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Warehouse address') }}</dt><dd>{{ $shipment->warehouse->address }} {{ $shipment->warehouse->city }}</dd>
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('History')">
                <ol class="flex flex-col gap-2 px-5 pb-5 text-sm">
                    @foreach ($shipment->transitions as $transition)
                        <li><x-ui.status-badge :status="$transition->to_status" /> <span class="text-fg-secondary">{{ $transition->created_at->diffForHumans() }}</span>@if ($transition->reason) · {{ $transition->reason }}@endif</li>
                    @endforeach
                </ol>
            </x-ui.card>
            @if (in_array($shipment->status->value, ['draft', 'submitted', 'in_transit'], true))
                <form method="POST" action="{{ route('partner.inventory.inbound.act', [$shipment->id, 'cancel']) }}">@csrf
                    <x-ui.button type="submit" variant="danger">{{ __('Cancel plan') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.panel>
