<x-ui.card :title="__('Items')" flush>
    <x-ui.table :headers="[__('Item'), __('Unit cost'), __('Quantity'), __('Total')]">
        @foreach ($order->items as $item)
            <tr class="border-t border-line">
                <td class="px-5 py-3">{{ $item->name() }}<span class="block text-xs text-fg-secondary">{{ $item->sku }}</span></td>
                <td class="px-5 py-3"><x-ui.money :amount="(string) $item->unit_cost" :currency="$order->currency" /></td>
                <td class="px-5 py-3">{{ $item->quantity }}</td>
                <td class="px-5 py-3"><x-ui.money :amount="(string) $item->line_total" :currency="$order->currency" /></td>
            </tr>
        @endforeach
        <tr class="border-t border-line"><td class="px-5 py-3 text-fg-secondary" colspan="3">{{ __('Delivery fee') }}</td><td class="px-5 py-3"><x-ui.money :amount="(string) $order->delivery_fee" :currency="$order->currency" /></td></tr>
        <tr class="border-t border-line font-semibold"><td class="px-5 py-3" colspan="3">{{ __('Total') }}</td><td class="px-5 py-3"><x-ui.money :amount="(string) $order->total" :currency="$order->currency" /></td></tr>
    </x-ui.table>
</x-ui.card>
<x-ui.card :title="__('Delivery')">
    <dl class="grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-fg-secondary">{{ __('Address') }}</dt><dd>{{ collect($order->delivery_address)->only(['building', 'street', 'area', 'city'])->filter()->implode(', ') }}</dd></div>
        <div><dt class="text-xs text-fg-secondary">{{ __('Carrier') }}</dt><dd>{{ $order->carrier_name ?? '—' }} {{ $order->tracking_number }}</dd></div>
        @if ($order->rejection_reason)
            <div class="sm:col-span-2"><dt class="text-xs text-fg-secondary">{{ __('Rejection reason') }}</dt><dd>{{ $order->rejection_reason }}</dd></div>
        @endif
    </dl>
</x-ui.card>
