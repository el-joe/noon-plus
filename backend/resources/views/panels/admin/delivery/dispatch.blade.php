<x-layouts.panel :title="__('Dispatch')" :subtitle="__(':country · assign parcels, follow attempts, handle failed deliveries · times in Oman time', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Dispatch')]">
    @include('panels.admin.delivery._nav')

    <x-ui.card :title="__('Ready to dispatch')" :subtitle="__('Packed or handed-over parcels without an agent')" flush>
        <x-ui.table :headers="[__('Parcel'), __('Seller'), __('Destination'), __('Payment'), __('Status'), '']" :rows="$ready->isNotEmpty()" :empty="__('Nothing waiting for an agent.')">
            @foreach ($ready as $subOrder)
                <tr class="border-t border-line align-top" data-ready="{{ $subOrder->id }}">
                    <td class="px-5 py-3 font-medium">{{ $subOrder->number }}</td>
                    <td class="px-5 py-3">{{ $subOrder->vendor?->store_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $subOrder->order->shipping_address['city'] ?? '—' }} · {{ $subOrder->order->shipping_address['area'] ?? '' }}</td>
                    <td class="px-5 py-3 text-xs uppercase">{{ $subOrder->order->payment_method }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subOrder->status" /></td>
                    <td class="px-5 py-3">
                        @can('delivery_assignments.manage')
                            <div class="flex flex-wrap items-end gap-2">
                                <form method="POST" action="{{ route('admin.delivery.assign', $subOrder->id) }}" class="flex items-end gap-2">
                                    @csrf
                                    <x-ui.select name="agent_id" :id="'agent-'.$subOrder->id" :options="$agents->pluck('name', 'id')->all()" :placeholder="__('Agent…')" />
                                    <x-ui.button type="submit" size="sm">{{ __('Assign') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('admin.delivery.assign', $subOrder->id) }}">
                                    @csrf
                                    <input type="hidden" name="auto" value="1">
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Auto-assign') }}</x-ui.button>
                                </form>
                                @if ($allocated->has($subOrder->id))
                                    <x-ui.badge tone="info">{{ __('With :carrier', ['carrier' => $carriers->get($allocated->get($subOrder->id), '—')]) }}</x-ui.badge>
                                @elseif ($carriers->isNotEmpty())
                                    <form method="POST" action="{{ route('admin.delivery.allocate', $subOrder->id) }}" class="flex items-end gap-2">
                                        @csrf
                                        <x-ui.select name="carrier_id" :id="'carrier-'.$subOrder->id" :options="$carriers->all()" :placeholder="__('Shipping company…')" />
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Hand over') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Needs attention')" :subtitle="__('Failed attempts and parcels returning to origin')" flush>
        <x-ui.table :headers="[__('Shipment'), __('Attempts'), __('Last reason'), __('Status'), '']" :rows="$attention->isNotEmpty()" :empty="__('No failed deliveries.')">
            @foreach ($attention as $shipment)
                <tr class="border-t border-line align-top" data-attention="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium">{{ $shipment->number }}</td>
                    <td class="px-5 py-3">{{ $shipment->attempts }}</td>
                    <td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', (string) $shipment->last_failure_reason) ?: '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" /></td>
                    <td class="px-5 py-3">
                        @can('delivery_assignments.manage')
                            @if ($shipment->status === \App\Domains\Delivery\Enums\ShipmentStatus::Failed)
                                <div class="flex flex-wrap items-end gap-2">
                                    <form method="POST" action="{{ route('admin.delivery.shipments.reschedule', $shipment->id) }}" class="flex items-end gap-2">
                                        @csrf
                                        <x-ui.input type="date" :label="__('New day (:zone)', ['zone' => $country->timezone])" name="date" :id="'date-'.$shipment->id" />
                                        <x-ui.button type="submit" size="sm">{{ __('Reschedule') }}</x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.delivery.shipments.rto', $shipment->id) }}" class="flex items-end gap-2">
                                        @csrf
                                        <x-ui.input :label="__('Reason')" name="reason" :id="'rto-'.$shipment->id" />
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Return to origin') }}</x-ui.button>
                                    </form>
                                </div>
                            @else
                                <form method="POST" action="{{ route('admin.delivery.shipments.returned', $shipment->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('Confirm back at origin') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Assignments')" flush>
        <x-slot:actions>
            <form method="GET" class="flex items-end gap-2">
                <x-ui.select name="status" :options="collect(\App\Domains\Delivery\Enums\AssignmentStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" :selected="$filters['status'] ?? null" :placeholder="__('All statuses')" />
                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <x-ui.table :headers="[__('Assignment'), __('Agent'), __('Attempt'), __('SLA due (Oman)'), __('Status')]" :rows="$assignments->isNotEmpty()" :empty="__('No assignments yet.')">
            @foreach ($assignments as $assignment)
                <tr class="border-t border-line" data-assignment="{{ $assignment->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.delivery.assignments.show', $assignment->id) }}">{{ $assignment->number }}</a></td>
                    <td class="px-5 py-3">{{ $assignment->agent->name }}</td>
                    <td class="px-5 py-3">{{ $assignment->attempt_no }}</td>
                    <td class="px-5 py-3 text-xs">{{ $assignment->sla_due_at ? $clock->inAdminTime($assignment->sla_due_at)->format('j M H:i') : '—' }} @if ($assignment->sla_breached_at)<x-ui.badge tone="danger">{{ __('SLA breached') }}</x-ui.badge>@endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$assignment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $assignments->links() }}
</x-layouts.panel>
