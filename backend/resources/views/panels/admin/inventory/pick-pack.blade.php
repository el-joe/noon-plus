<x-layouts.panel :title="__('Pick & pack')" :subtitle="__('FBN orders: confirm, pick, pack and dispatch to delivery')" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Pick & pack')]">
    @include('panels.admin.inventory._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Order'), __('Placed'), __('Pick list'), __('Status'), '']" :rows="$queue->isNotEmpty()" :empty="__('No FBN orders to fulfil.')">
            @foreach ($queue as $subOrder)
                @php($next = $orders->nextStep($subOrder->status))
                <tr class="border-t border-line align-top" data-pick-order="{{ $subOrder->id }}">
                    <td class="px-5 py-3 font-medium">{{ $subOrder->number }}</td>
                    <td class="px-5 py-3 text-fg-secondary">{{ $subOrder->order?->placed_at?->setTimezone($country->timezone)->format('j M, H:i') }}</td>
                    <td class="px-5 py-3">
                        <ul class="flex flex-col gap-1">
                            @foreach ($reservations->get($subOrder->id, collect()) as $reservation)
                                @php($item = $subOrder->items->firstWhere('id', $reservation->order_item_id))
                                <li>{{ $reservation->warehouse->code }} · {{ $bins->get($reservation->warehouse_id.':'.$reservation->listing_id)?->bin_code ?? __('no bin') }} · {{ $item?->sku }} × {{ $reservation->quantity }}
                                    @if ($reservation->status->value !== 'reserved')<x-ui.status-badge :status="$reservation->status" />@endif</li>
                            @endforeach
                        </ul>
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subOrder->status" /></td>
                    <td class="px-5 py-3">
                        @can('fbn.operate')
                            @if ($next)
                                <form method="POST" action="{{ route('admin.inventory.pick-pack.advance', $subOrder->id) }}">@csrf
                                    <input type="hidden" name="to" value="{{ $next->value }}">
                                    <x-ui.button type="submit" size="sm">{{ $orders->stepLabel($next) }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $queue->links() }}
</x-layouts.panel>
