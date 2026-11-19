<x-layouts.panel :title="__('Removals')" :subtitle="__('Take FBN units back or have them disposed of')" :breadcrumbs="[__('Inventory & warehouses'), __('Removals')]">
    @include('panels.partner.inventory._nav')

    <x-ui.card :title="__('Removal requests')" flush>
        <x-ui.table :headers="[__('Reference'), __('Type'), __('Warehouse'), __('Units'), __('Fee'), __('Status'), '']" :rows="$removals->isNotEmpty()" :empty="__('No removals requested.')">
            @foreach ($removals as $removal)
                <tr class="border-t border-line align-top" data-removal="{{ $removal->id }}">
                    <td class="px-5 py-3 font-medium">{{ $removal->number }}
                        <span class="block text-xs text-fg-secondary">{{ $removal->lines->map(fn ($line) => $line->listing->variant->sku.' × '.$line->quantity.($line->source === 'damaged' ? ' ('.__('damaged').')' : ''))->implode(', ') }}</span>
                    </td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($removal->type) }}</td>
                    <td class="px-5 py-3">{{ $removal->warehouse->code }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $removal->lines->sum('quantity') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$removal->fee" :currency="$removal->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$removal->status" /></td>
                    <td class="px-5 py-3">
                        @if ($removal->status === \App\Domains\Fulfilment\Enums\RemovalStatus::Requested)
                            <form method="POST" action="{{ route('partner.inventory.removals.cancel', $removal->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="ghost">{{ __('Cancel') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $removals->links() }}

    <x-ui.card :title="__('Request a removal')" :subtitle="__('Fee: :per_unit per unit + :per_kg per kg, at least :minimum (disposal :disposal per unit). Charged when the removal is done.', ['per_unit' => $fees['removal_per_unit'], 'per_kg' => $fees['removal_per_kg'], 'minimum' => $fees['removal_minimum'].' '.$currency, 'disposal' => $fees['disposal_per_unit']])">
        @if ($rows->isEmpty())
            <p class="px-5 pb-5 text-sm text-fg-secondary">{{ __('You have no units in a Nawy warehouse.') }}</p>
        @else
            <form method="POST" action="{{ route('partner.inventory.removals.store') }}" class="flex flex-col gap-4 px-5 pb-5" data-removal-form>
                @csrf
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.select :label="__('Warehouse')" name="warehouse_id" :options="$warehouses->mapWithKeys(fn ($warehouse) => [$warehouse->id => $warehouse->code.' · '.$warehouse->name()])->all()" />
                    <x-ui.select :label="__('Type')" name="type" :options="['return_to_seller' => __('Return to seller'), 'disposal' => __('Disposal')]" />
                    <x-ui.input :label="__('Reason')" name="reason" />
                    <x-ui.input :label="__('Contact name')" name="address[name]" id="address-name" />
                    <x-ui.input :label="__('Phone')" name="address[phone]" id="address-phone" />
                    <x-ui.input :label="__('City')" name="address[city]" id="address-city" />
                    <div class="sm:col-span-3"><x-ui.input :label="__('Street address (for returns)')" name="address[street]" id="address-street" /></div>
                </div>
                <x-ui.table :headers="[__('Product'), __('In warehouse'), __('Units to remove'), __('From')]">
                    @foreach ($rows->values() as $index => $row)
                        <tr class="border-t border-line">
                            <td class="px-3 py-2">{{ $row['title'] }} · {{ $row['sku'] }}<input type="hidden" name="lines[{{ $index }}][listing_id]" value="{{ $row['listing']->id }}"></td>
                            <td class="px-3 py-2 text-xs text-fg-secondary">{{ __(':available sellable, :damaged damaged', ['available' => $row['available'], 'damaged' => $row['damaged']]) }}</td>
                            <td class="px-3 py-2"><x-ui.input :name="'lines['.$index.'][quantity]'" :id="'removal-quantity-'.$index" type="number" min="0" :label="__('Units to remove')" /></td>
                            <td class="px-3 py-2"><x-ui.select :name="'lines['.$index.'][source]'" :id="'removal-source-'.$index" :options="['sellable' => __('Sellable'), 'damaged' => __('Damaged')]" :aria-label="__('From')" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
                <div><x-ui.button type="submit">{{ __('Request removal') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>
</x-layouts.panel>
