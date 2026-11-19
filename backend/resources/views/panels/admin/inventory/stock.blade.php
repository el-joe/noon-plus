<x-layouts.panel :title="__('Stock & adjustments')" :subtitle="__('Units per warehouse and SKU; every change is a movement with its reason')" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Stock & adjustments')]">
    @include('panels.admin.inventory._nav')

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <x-ui.input :label="__('SKU or store')" name="q" :value="$filters['q'] ?? null" />
        <x-ui.select :label="__('Warehouse')" name="warehouse_id" :selected="$filters['warehouse_id'] ?? null" :placeholder="__('All warehouses')" :options="$warehouses->pluck('code', 'id')->all()" />
        <x-ui.select :label="__('Show')" name="flag" :selected="$filters['flag'] ?? null" :placeholder="__('Everything')" :options="['reserved' => __('Reserved'), 'damaged' => __('Damaged'), 'long_term' => __('Long-term storage'), 'unsellable' => __('Unsellable')]" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Warehouse'), __('SKU'), __('Product'), __('Store'), __('Bin'), __('On hand'), __('Reserved'), __('Available'), __('Damaged'), __('In since')]" :rows="$rows->isNotEmpty()" :empty="__('No stock.')">
            @foreach ($rows as $row)
                <tr class="border-t border-line" data-stock-row="{{ $row->id }}">
                    <td class="px-5 py-3">{{ $row->warehouse->code }}</td>
                    <td class="px-5 py-3 font-medium">{{ $row->listing->variant->sku }}</td>
                    <td class="px-5 py-3">{{ $row->listing->variant->product->title() }}</td>
                    <td class="px-5 py-3">{{ $row->listing->vendor->store_name }}</td>
                    <td class="px-5 py-3">{{ $row->bin_code ?? '—' }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $row->on_hand }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $row->reserved }}</td>
                    <td class="px-5 py-3 font-semibold tabular-nums">{{ $row->available() }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $row->damaged }}</td>
                    <td class="px-5 py-3 text-fg-secondary">{{ $row->first_received_at?->toDateString() ?? '—' }}
                        @if ($row->long_term_flagged_at)<x-ui.badge tone="danger">{{ __('Long-term storage') }}</x-ui.badge>@endif
                        @if ($row->unsellable_flagged_at)<x-ui.badge tone="danger">{{ __('Unsellable') }}</x-ui.badge>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $rows->links() }}

    @can('fbn.operate')
        <x-ui.card :title="__('Adjust stock')" :subtitle="__('On hand changes what can be sold; damaged does not. Reserved units follow their orders.')">
            <form method="POST" action="{{ route('admin.inventory.stock.adjust') }}" class="grid gap-4 px-5 pb-5 sm:grid-cols-3" data-adjust-form>
                @csrf
                <x-ui.select :label="__('Warehouse')" name="warehouse_id" :options="$warehouses->pluck('code', 'id')->all()" />
                <x-ui.input :label="__('SKU')" name="sku" required />
                <x-ui.select :label="__('Counter')" name="bucket" :options="['on_hand' => __('On hand (sellable)'), 'damaged' => __('Damaged'), 'mark_damaged' => __('Move sellable to damaged')]" />
                <x-ui.input :label="__('Change (+/−)')" name="quantity" type="number" required />
                <x-ui.select :label="__('Reason')" name="reason" :options="collect($reasons)->mapWithKeys(fn ($reason) => [$reason => \App\Shared\Localization\Label::of($reason)])->all()" />
                <x-ui.input :label="__('Note (required for the audit)')" name="note" required />
                <div><x-ui.button type="submit">{{ __('Apply adjustment') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card :title="__('Recent adjustments')" flush>
        <x-ui.table :headers="[__('When'), __('Warehouse'), __('SKU'), __('Counter'), __('Change'), __('Reason'), __('Note'), __('By')]" :rows="$movements->isNotEmpty()" :empty="__('No adjustments yet.')">
            @foreach ($movements as $movement)
                <tr class="border-t border-line">
                    <td class="px-5 py-2 text-fg-secondary">{{ $movement->created_at?->diffForHumans() }}</td>
                    <td class="px-5 py-2">{{ $movement->warehouse->code }}</td>
                    <td class="px-5 py-2">{{ $movement->listing->variant->sku }}</td>
                    <td class="px-5 py-2">{{ \App\Shared\Localization\Label::of($movement->bucket) }}</td>
                    <td class="px-5 py-2 tabular-nums">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                    <td class="px-5 py-2">{{ \App\Shared\Localization\Label::of($movement->reason) }}</td>
                    <td class="px-5 py-2">{{ $movement->note }}</td>
                    <td class="px-5 py-2">{{ $movement->actor_user_id ? app(\App\Shared\Support\UserNames::class)->name($movement->actor_user_id) : __('System') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
