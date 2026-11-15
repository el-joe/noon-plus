<x-layouts.panel :title="__('Orders')" :subtitle="__('Your shipments in :country · times in :zone', ['country' => $country->name_en, 'zone' => $country->timezone])" :breadcrumbs="[__('Seller Center'), __('Orders')]">
    <form method="GET" action="{{ route('partner.orders') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3" role="search">
        <x-ui.input :label="__('Order number')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Orders\Enums\SubOrderStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Order'), __('Placed'), __('Items'), __('Total'), __('Ship by'), __('Status')]" :rows="$subOrders->isNotEmpty()" :empty="__('No orders match these filters.')">
            @foreach ($subOrders as $subOrder)
                <tr class="border-t border-line" data-sub-order="{{ $subOrder->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('partner.orders.show', $subOrder->id) }}">{{ $subOrder->number }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $subOrder->order->placed_at->setTimezone($country->timezone)->format('j M, H:i') }}</td>
                    <td class="px-5 py-3">{{ $subOrder->items_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$subOrder->total" :currency="$country->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $subOrder->estimated_delivery_from?->format('j M') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subOrder->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $subOrders->links() }}
</x-layouts.panel>
