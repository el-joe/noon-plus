@php($status = $shipment->status)
<x-layouts.panel :title="__('Inbound :number', ['number' => $shipment->reference()])" :subtitle="__(':store → :warehouse', ['store' => $shipment->vendor->store_name, 'warehouse' => $shipment->warehouse->code])" :breadcrumbs="[__('Inventory & fulfilment'), __('Receiving'), $shipment->reference()]">
    <x-slot:actions>
        @can('fbn.operate')
            @if (in_array($status->value, ['submitted', 'in_transit'], true))
                <form method="POST" action="{{ route('admin.inventory.inbound.act', [$shipment->id, 'arrive']) }}">@csrf<x-ui.button type="submit">{{ __('Mark arrived') }}</x-ui.button></form>
            @elseif ($status->value === 'arrived')
                <form method="POST" action="{{ route('admin.inventory.inbound.act', [$shipment->id, 'receive']) }}">@csrf<x-ui.button type="submit">{{ __('Start receiving') }}</x-ui.button></form>
            @elseif ($status->value === 'receiving')
                <form method="POST" action="{{ route('admin.inventory.inbound.act', [$shipment->id, 'finish']) }}">@csrf<x-ui.button type="submit">{{ __('Finish receiving') }}</x-ui.button></form>
            @elseif ($status->value === 'received')
                <form method="POST" action="{{ route('admin.inventory.inbound.act', [$shipment->id, 'close']) }}">@csrf<x-ui.button type="submit">{{ __('Close') }}</x-ui.button></form>
            @endif
        @endcan
    </x-slot:actions>

    @include('panels.admin.inventory._nav')

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Lines')" flush class="xl:col-span-2">
            <x-ui.table :headers="[__('SKU'), __('Product'), __('Expected'), __('Received'), __('Damaged'), __('Short'), __('Bin'), '']">
                @foreach ($shipment->lines as $line)
                    <tr class="border-t border-line align-top" data-inbound-line="{{ $line->id }}">
                        <td class="px-5 py-3 font-medium">{{ $line->listing->variant->sku }}</td>
                        <td class="px-5 py-3">{{ $line->listing->variant->product->title() }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $line->quantity_expected }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $line->quantity_received }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $line->quantity_damaged }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $line->shortQuantity() }}</td>
                        <td class="px-5 py-3">{{ $line->bin_code ?? '—' }}</td>
                        <td class="px-5 py-3">
                            @if ($status->value === 'receiving' && $line->shortQuantity() > 0)
                                @can('fbn.operate')
                                    <form method="POST" action="{{ route('admin.inventory.inbound.lines.receive', $line->id) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <x-ui.input :label="__('Good')" name="good" :id="'good-'.$line->id" type="number" min="0" :value="$line->shortQuantity()" class="w-20" />
                                        <x-ui.input :label="__('Damaged')" name="damaged" :id="'damaged-'.$line->id" type="number" min="0" value="0" class="w-20" />
                                        <x-ui.input :label="__('Bin')" name="bin_code" :id="'bin-'.$line->id" :value="$line->bin_code" class="w-24" />
                                        <x-ui.button type="submit" size="sm">{{ __('Put away') }}</x-ui.button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Shipment')">
                <dl class="grid grid-cols-2 gap-2 px-5 pb-5 text-sm">
                    <dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$status" /></dd>
                    <dt class="text-fg-secondary">{{ __('Cartons') }}</dt><dd>{{ $shipment->cartons }}</dd>
                    <dt class="text-fg-secondary">{{ __('Expected arrival') }}</dt><dd>{{ $shipment->expected_at?->toDateString() ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Carrier') }}</dt><dd>{{ $shipment->carrier_name ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Tracking number') }}</dt><dd>{{ $shipment->tracking_number ?? '—' }}</dd>
                    <dt class="text-fg-secondary">{{ __('Notes') }}</dt><dd>{{ $shipment->notes ?? '—' }}</dd>
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('History')">
                <ol class="flex flex-col gap-2 px-5 pb-5 text-sm">
                    @foreach ($shipment->transitions as $transition)
                        <li><x-ui.status-badge :status="$transition->to_status" /> <span class="text-fg-secondary">{{ \App\Shared\Localization\Label::of($transition->actor_type) }}{{ $transition->actor_user_id ? ' '.app(\App\Shared\Support\UserNames::class)->name($transition->actor_user_id) : '' }} · {{ $transition->created_at->diffForHumans() }}</span>@if ($transition->reason) · {{ $transition->reason }}@endif</li>
                    @endforeach
                </ol>
            </x-ui.card>
            @can('fbn.operate')
                @if (in_array($status->value, ['submitted', 'in_transit'], true))
                    <form method="POST" action="{{ route('admin.inventory.inbound.act', [$shipment->id, 'cancel']) }}" class="flex items-end gap-2">@csrf
                        <x-ui.input :label="__('Reason')" name="reason" required />
                        <x-ui.button type="submit" variant="danger">{{ __('Cancel shipment') }}</x-ui.button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
</x-layouts.panel>
