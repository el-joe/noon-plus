<x-layouts.panel :title="__('Pickup requests')" :subtitle="__(':count parcels handed to :company and waiting for a driver', ['count' => $shipments->total(), 'company' => $currentCarrier->name])" :breadcrumbs="[__('Shipments'), __('Pickup requests')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Pickup'), __('Origin'), __('Destination'), __('Payment'), __('Status'), __('Assign')]" :rows="$shipments->isNotEmpty()" :empty="__('No parcels waiting for a driver.')">
            @foreach ($shipments as $shipment)
                <tr class="border-t border-line align-top" data-pickup="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium">{{ $shipment->number }}<p class="text-xs text-fg-secondary">{{ $shipment->subOrder->number }}</p></td>
                    <td class="px-5 py-3 text-sm">{{ $shipment->subOrder->vendor?->store_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $shipment->subOrder->order->shipping_address['city'] ?? '—' }} · {{ $shipment->subOrder->order->shipping_address['area'] ?? '' }}</td>
                    <td class="px-5 py-3 text-xs uppercase">{{ $shipment->subOrder->order->payment_method }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" /></td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap items-end gap-2">
                            <form method="POST" action="{{ route('carrier.pickups.assign', $shipment->id) }}" class="flex items-end gap-2">
                                @csrf
                                <x-ui.select name="agent_id" :id="'agent-'.$shipment->id" :options="$agents->pluck('name', 'id')->all()" :placeholder="__('Driver…')" />
                                <x-ui.button type="submit" size="sm">{{ __('Assign') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('carrier.pickups.assign', $shipment->id) }}">
                                @csrf
                                <input type="hidden" name="auto" value="1">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Auto-assign') }}</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $shipments->links() }}
    <p class="text-xs text-fg-secondary">{{ __('The seller is notified on assignment; the customer gets the delivery code when the driver starts.') }}</p>
</x-layouts.panel>
