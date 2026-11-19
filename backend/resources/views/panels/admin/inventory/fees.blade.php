<x-layouts.panel :title="__('FBN fees')" :subtitle="__(':country · amounts in :currency, before VAT', ['country' => $country->name_en, 'currency' => $country->currency])" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('FBN fees')]">
    @include('panels.admin.inventory._nav')

    <form method="POST" action="{{ route('admin.inventory.fees.update') }}" class="flex flex-col gap-6" data-fees-form>
        @csrf @method('PUT')
        <x-ui.card :title="__('Fulfilment (outbound) fee per unit')" :subtitle="__('By billable weight; the price band decides the column.')" flush>
            <div class="grid gap-4 px-5 pb-4 sm:grid-cols-3">
                <x-ui.input :label="__('Price threshold')" name="outbound_price_threshold" :value="$fees['outbound_price_threshold']" inputmode="decimal" :helper="__('Default :value', ['value' => $defaults['outbound_price_threshold']])" />
                <x-ui.input :label="__('Per extra kg above the last tier')" name="outbound_extra_per_kg" :value="$fees['outbound_extra_per_kg']" inputmode="decimal" />
            </div>
            <x-ui.table :headers="[__('Up to (grams)'), __('Price at or under the threshold'), __('Price above')]">
                @foreach (array_pad($fees['outbound_tiers'], max(count($fees['outbound_tiers']) + 2, 10), ['max_grams' => null, 'low' => null, 'high' => null]) as $index => $tier)
                    <tr class="border-t border-line">
                        <td class="px-5 py-2"><x-ui.input :name="'outbound_tiers['.$index.'][max_grams]'" :id="'tier-grams-'.$index" :value="$tier['max_grams']" type="number" min="1" :label="__('Up to (grams)')" /></td>
                        <td class="px-5 py-2"><x-ui.input :name="'outbound_tiers['.$index.'][low]'" :id="'tier-low-'.$index" :value="$tier['low']" inputmode="decimal" :label="__('Price at or under the threshold')" /></td>
                        <td class="px-5 py-2"><x-ui.input :name="'outbound_tiers['.$index.'][high]'" :id="'tier-high-'.$index" :value="$tier['high']" inputmode="decimal" :label="__('Price above')" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Storage and removal')">
            <div class="grid gap-4 px-5 pb-5 sm:grid-cols-3">
                <x-ui.input :label="__('Storage per cubic foot per month')" name="storage_per_cubic_foot" :value="$fees['storage_per_cubic_foot']" inputmode="decimal" />
                <x-ui.input :label="__('Long-term storage per cubic foot per month')" name="long_term_per_cubic_foot" :value="$fees['long_term_per_cubic_foot']" inputmode="decimal" />
                <x-ui.input :label="__('Unsellable storage per cubic foot per month')" name="unsellable_per_cubic_foot" :value="$fees['unsellable_per_cubic_foot']" inputmode="decimal" />
                <x-ui.input :label="__('Removal minimum per order')" name="removal_minimum" :value="$fees['removal_minimum']" inputmode="decimal" />
                <x-ui.input :label="__('Removal per unit')" name="removal_per_unit" :value="$fees['removal_per_unit']" inputmode="decimal" />
                <x-ui.input :label="__('Removal per kg')" name="removal_per_kg" :value="$fees['removal_per_kg']" inputmode="decimal" />
                <x-ui.input :label="__('Disposal per unit')" name="disposal_per_unit" :value="$fees['disposal_per_unit']" inputmode="decimal" />
            </div>
        </x-ui.card>

        @can('fbn.configure')
            <div><x-ui.button type="submit">{{ __('Save fees') }}</x-ui.button></div>
        @endcan
    </form>
</x-layouts.panel>
