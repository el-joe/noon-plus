<x-layouts.panel :title="__('New inbound plan')" :subtitle="__('Tell the warehouse what is coming: products, units and cartons')" :breadcrumbs="[__('Inventory & warehouses'), __('Inbound plans'), __('New')]">
    @include('panels.partner.inventory._nav')

    @if ($warehouses->isEmpty())
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('No FBN warehouse is open in this country yet. You can enrol products now; inbound plans open with the first warehouse.') }}</div>
    @elseif ($listings->isEmpty())
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Enrol a product in Fulfilled by Nawy first.') }} <a class="underline" href="{{ route('partner.inventory') }}">{{ __('Inventory & warehouses') }}</a></div>
    @else
        <form method="POST" action="{{ route('partner.inventory.inbound.store') }}" class="flex flex-col gap-6" data-inbound-form>
            @csrf
            <x-ui.card :title="__('Shipment')">
                <div class="grid gap-4 px-5 pb-5 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.select :label="__('Destination warehouse')" name="warehouse_id" :options="$warehouses->mapWithKeys(fn ($warehouse) => [$warehouse->id => $warehouse->code.' · '.$warehouse->name().' ('.$warehouse->city.')'])->all()" />
                    <x-ui.input :label="__('Expected arrival')" name="expected_at" type="date" />
                    <x-ui.input :label="__('Total cartons')" name="cartons" type="number" min="1" max="999" :helper="__('Leave empty to add up the lines.')" />
                    <x-ui.input :label="__('Notes for the warehouse')" name="notes" />
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Products')" :subtitle="__('Only products fulfilled by Nawy are listed. Empty rows are ignored.')" flush>
                <x-ui.table :headers="[__('Product'), __('Units'), __('Cartons')]">
                    @for ($row = 0; $row < 8; $row++)
                        <tr class="border-t border-line">
                            <td class="px-5 py-2">
                                <x-ui.select :name="'lines['.$row.'][listing_id]'" :id="'line-listing-'.$row" :placeholder="__('Choose a product')" :aria-label="__('Product')"
                                             :options="$listings->mapWithKeys(fn ($listing) => [$listing->id => $listing->variant->product->title().' · '.$listing->variant->sku])->all()" />
                            </td>
                            <td class="px-5 py-2"><x-ui.input :name="'lines['.$row.'][quantity]'" :id="'line-quantity-'.$row" type="number" min="1" :label="__('Units')" /></td>
                            <td class="px-5 py-2"><x-ui.input :name="'lines['.$row.'][cartons]'" :id="'line-cartons-'.$row" type="number" min="1" :label="__('Cartons')" /></td>
                        </tr>
                    @endfor
                </x-ui.table>
            </x-ui.card>

            <div class="flex gap-3">
                <x-ui.button type="submit" name="intent" value="submit">{{ __('Submit plan') }}</x-ui.button>
                <x-ui.button type="submit" name="intent" value="draft" variant="outline">{{ __('Save draft') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.panel>
