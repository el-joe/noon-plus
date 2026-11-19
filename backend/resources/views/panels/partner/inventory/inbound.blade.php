<x-layouts.panel :title="__('Inbound plans')" :subtitle="__('Send stock to a Nawy warehouse: plan, submit, print labels, ship')" :breadcrumbs="[__('Inventory & warehouses'), __('Inbound plans')]">
    <x-slot:actions>
        @if (app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'partner', 'inventory', true))
            <x-ui.button :href="route('partner.inventory.inbound.create')">{{ __('New inbound plan') }}</x-ui.button>
        @endif
    </x-slot:actions>

    @include('panels.partner.inventory._nav')

    <form method="GET" class="flex items-end gap-3">
        <x-ui.select :label="__('Status')" name="status" :selected="$status" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Fulfilment\Enums\InboundStatus::cases())->mapWithKeys(fn ($case) => [$case->value => \App\Shared\Localization\Label::of($case)])->all()" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Reference'), __('Warehouse'), __('SKUs'), __('Units'), __('Cartons'), __('Status'), __('Updated')]" :rows="$shipments->isNotEmpty()" :empty="__('No inbound plans yet.')">
            @foreach ($shipments as $shipment)
                <tr class="border-t border-line" data-inbound="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-brand underline" href="{{ route('partner.inventory.inbound.show', $shipment->id) }}">{{ $shipment->reference() }}</a></td>
                    <td class="px-5 py-3">{{ $shipment->warehouse->code }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $shipment->lines_count }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ (int) $shipment->lines_sum_quantity_expected }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $shipment->cartons }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" /></td>
                    <td class="px-5 py-3 text-fg-secondary">{{ $shipment->updated_at->diffForHumans() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $shipments->links() }}
</x-layouts.panel>
