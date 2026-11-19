<x-layouts.panel :title="__('Inventory & fulfilment')" :subtitle="__(':country · Fulfilled by Nawy warehouses, receiving and order fulfilment', ['country' => $country->name_en])" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment')]">
    @include('panels.admin.inventory._nav')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('admin.inventory.inbound') }}"><x-ui.stat-card :label="__('Inbound on the way')" :value="number_format($counts['inbound'])" /></a>
        <a href="{{ route('admin.inventory.inbound', ['status' => 'arrived']) }}"><x-ui.stat-card :label="__('Waiting to be received')" :value="number_format($counts['arrived'])" /></a>
        <a href="{{ route('admin.inventory.pick-pack') }}"><x-ui.stat-card :label="__('Orders to pick & pack')" :value="number_format($counts['pick_pack'])" /></a>
        <a href="{{ route('admin.inventory.removals') }}"><x-ui.stat-card :label="__('Open removals')" :value="number_format($counts['removals'])" /></a>
        <a href="{{ route('admin.inventory.transfers') }}"><x-ui.stat-card :label="__('Open transfers')" :value="number_format($counts['transfers'])" /></a>
        <a href="{{ route('admin.inventory.stock') }}"><x-ui.stat-card :label="__('SKUs in stock')" :value="number_format($counts['skus'])" /></a>
        <a href="{{ route('admin.inventory.stock', ['flag' => 'long_term']) }}"><x-ui.stat-card :label="__('Long-term storage SKUs')" :value="number_format($counts['long_term'])" /></a>
        <a href="{{ route('admin.inventory.stock', ['flag' => 'unsellable']) }}"><x-ui.stat-card :label="__('Unsellable SKUs')" :value="number_format($counts['unsellable'])" /></a>
    </div>

    <x-ui.card :title="__('Warehouses')" flush>
        <x-ui.table :headers="[__('Code'), __('Name'), __('City'), __('On hand'), __('Reserved'), __('Damaged'), __('Capacity'), __('Status')]" :rows="$warehouses->isNotEmpty()" :empty="__('No warehouses yet. Add the first one under Warehouses.')">
            @foreach ($warehouses as $warehouse)
                <tr class="border-t border-line" data-warehouse="{{ $warehouse->id }}">
                    <td class="px-5 py-3 font-medium">{{ $warehouse->code }}</td>
                    <td class="px-5 py-3">{{ $warehouse->name() }}</td>
                    <td class="px-5 py-3">{{ $warehouse->city }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ (int) $warehouse->stock_sum_on_hand }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ (int) $warehouse->stock_sum_reserved }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ (int) $warehouse->stock_sum_damaged }}</td>
                    <td class="px-5 py-3">{{ $warehouse->capacity_cubic_feet ? number_format((float) $warehouse->capacity_cubic_feet).' ft³' : '—' }}
                        @if ($warehouse->over_capacity_since)<x-ui.badge tone="danger">{{ __('Over capacity') }}</x-ui.badge>@endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$warehouse->is_active ? 'active' : 'inactive'" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Latest stock movements')" flush>
        <x-ui.table :headers="[__('When'), __('Warehouse'), __('SKU'), __('Counter'), __('Change'), __('Reason')]" :rows="$recent->isNotEmpty()" :empty="__('No stock movements yet.')">
            @foreach ($recent as $movement)
                <tr class="border-t border-line">
                    <td class="px-5 py-2 text-fg-secondary">{{ $movement->created_at?->diffForHumans() }}</td>
                    <td class="px-5 py-2">{{ $movement->warehouse->code }}</td>
                    <td class="px-5 py-2">{{ $movement->listing->variant->sku }}</td>
                    <td class="px-5 py-2">{{ \App\Shared\Localization\Label::of($movement->bucket) }}</td>
                    <td class="px-5 py-2 tabular-nums">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                    <td class="px-5 py-2">{{ \App\Shared\Localization\Label::of($movement->reason) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
