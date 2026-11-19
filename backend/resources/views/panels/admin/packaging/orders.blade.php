<x-layouts.panel :title="__('Packaging orders')" :subtitle="__('Stores\' packaging orders')" :breadcrumbs="[__('Operations'), __('Packaging'), __('Packaging orders')]">
    @include('panels.admin.packaging._nav')
    <form method="GET" class="flex items-end gap-3">
        <x-ui.select :label="__('Status')" name="status" :placeholder="__('All')" :selected="$filters['status'] ?? null"
                     :options="collect(\App\Domains\Fulfilment\Enums\PackagingOrderStatus::cases())->mapWithKeys(fn ($case) => [$case->value => __(\App\Shared\Localization\Label::key($case))])->all()" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Order'), __('Store'), __('Items'), __('Total'), __('Placed (Oman)'), __('Status')]" :rows="$orders->isNotEmpty()" :empty="__('No packaging orders.')">
            @foreach ($orders as $order)
                <tr class="border-t border-line" data-packaging-order="{{ $order->number }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.packaging.orders.show', $order->id) }}">{{ $order->number }}</a></td>
                    <td class="px-5 py-3">{{ $order->vendor?->store_name }}</td>
                    <td class="px-5 py-3">{{ $order->items_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $order->total" :currency="$order->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $order->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$order->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $orders->links() }}
</x-layouts.panel>
