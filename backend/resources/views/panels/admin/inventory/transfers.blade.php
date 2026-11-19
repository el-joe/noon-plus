<x-layouts.panel :title="__('Transfers')" :subtitle="__('Move stock between warehouses: requested → in transit → received')" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Transfers')]">
    @include('panels.admin.inventory._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Transfer'), __('From'), __('To'), __('Lines'), __('Status'), '']" :rows="$transfers->isNotEmpty()" :empty="__('No transfers yet.')">
            @foreach ($transfers as $transfer)
                <tr class="border-t border-line align-top" data-transfer="{{ $transfer->id }}">
                    <td class="px-5 py-3 font-medium">{{ $transfer->number }}<span class="block text-xs text-fg-secondary">{{ $transfer->note }}</span></td>
                    <td class="px-5 py-3">{{ $transfer->from->code }}</td>
                    <td class="px-5 py-3">{{ $transfer->to->code }}</td>
                    <td class="px-5 py-3">
                        @foreach ($transfer->lines as $line)
                            <span class="block">{{ $line->listing->variant->sku }} × {{ $line->quantity }}@if ($transfer->status->value === 'received') ({{ __(':count received', ['count' => $line->quantity_received]) }})@endif</span>
                        @endforeach
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$transfer->status" /></td>
                    <td class="px-5 py-3">
                        @can('fbn.operate')
                            @if ($transfer->status->value === 'requested')
                                <form method="POST" action="{{ route('admin.inventory.transfers.act', [$transfer->id, 'dispatch']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Dispatch') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('admin.inventory.transfers.act', [$transfer->id, 'cancel']) }}" class="mt-2">@csrf<x-ui.button type="submit" size="sm" variant="ghost">{{ __('Cancel') }}</x-ui.button></form>
                            @elseif ($transfer->status->value === 'in_transit')
                                <form method="POST" action="{{ route('admin.inventory.transfers.act', [$transfer->id, 'receive']) }}" class="flex flex-col gap-2">@csrf
                                    @foreach ($transfer->lines as $line)
                                        <x-ui.input :label="$line->listing->variant->sku" :name="'received['.$line->id.']'" :id="'received-'.$line->id" type="number" min="0" :max="$line->quantity" :value="$line->quantity" />
                                    @endforeach
                                    <x-ui.button type="submit" size="sm">{{ __('Receive') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $transfers->links() }}

    @can('fbn.operate')
        <x-ui.card :title="__('New transfer')">
            <form method="POST" action="{{ route('admin.inventory.transfers.store') }}" class="flex flex-col gap-4 px-5 pb-5">
                @csrf
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.select :label="__('From')" name="from_warehouse_id" :options="$warehouses->pluck('code', 'id')->all()" />
                    <x-ui.select :label="__('To')" name="to_warehouse_id" :options="$warehouses->pluck('code', 'id')->all()" />
                    <x-ui.input :label="__('Note')" name="note" />
                </div>
                @for ($row = 0; $row < 4; $row++)
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input :label="__('SKU')" :name="'lines['.$row.'][sku]'" :id="'transfer-sku-'.$row" />
                        <x-ui.input :label="__('Units')" :name="'lines['.$row.'][quantity]'" :id="'transfer-quantity-'.$row" type="number" min="1" />
                    </div>
                @endfor
                <div><x-ui.button type="submit">{{ __('Request transfer') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
