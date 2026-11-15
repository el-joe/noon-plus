<x-layouts.panel :title="__('Orders')" :subtitle="__(':country · dates and times in Oman time', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Orders')]">
    <form method="GET" action="{{ route('admin.orders') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3 xl:grid-cols-6" role="search">
        <x-ui.select :label="__('Country')" name="country" :selected="$country->code"
                     :options="$panelCountries->mapWithKeys(fn ($option) => [$option->code => $option->name_en])->all()" />
        <x-ui.input :label="__('Order number')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Orders\Enums\OrderStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <x-ui.input :label="__('Placed from (Oman)')" name="from" type="date" :value="$filters['from'] ?? ''" />
        <x-ui.input :label="__('Placed to (Oman)')" name="to" type="date" :value="$filters['to'] ?? ''" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Order'), __('Placed (Oman)'), __('Shipments'), __('Payment'), __('Total'), __('Status')]" :rows="$orders->isNotEmpty()" :empty="__('No orders match these filters.')">
            @foreach ($orders as $order)
                <tr class="border-t border-line" data-order="{{ $order->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.orders.show', $order->id) }}">{{ $order->number }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($order->placed_at)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">{{ $order->sub_orders_count }}</td>
                    <td class="px-5 py-3 uppercase">{{ $order->payment_method }} · {{ $order->payment_status }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$order->grand_total" :currency="$order->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$order->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $orders->links() }}
</x-layouts.panel>
