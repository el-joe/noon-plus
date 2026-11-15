<x-layouts.panel :title="$zone->name" :subtitle="__(':country · amounts in :currency, VAT included', ['country' => $country->name_en, 'currency' => $country->currency])"
                 :breadcrumbs="[__('Delivery & logistics'), __('Shipping zones'), $zone->name]">
    @php($canConfigure = auth()->user()->can('shipping_zones.configure'))

    @if ($canConfigure)
        <x-ui.card :title="__('Zone settings')">
            <form method="POST" action="{{ route('admin.delivery.zones.update', $zone->id) }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                @method('PUT')
                <x-ui.input :label="__('Name')" name="name" :value="$zone->name" required />
                <x-ui.input :label="__('Seller share of the subsidy (%)')" name="vendor_subsidy_share_percent" :value="$zone->vendor_subsidy_share_percent" required />
                <div class="flex flex-col justify-end gap-2">
                    <x-ui.checkbox :label="__('Default zone')" name="is_default" :checked="$zone->is_default" />
                    <x-ui.checkbox :label="__('Exceptional (subsidised) zone')" name="is_exceptional" :checked="$zone->is_exceptional" />
                    <x-ui.checkbox :label="__('Active')" name="is_active" :checked="$zone->is_active" />
                </div>
                <div class="sm:col-span-3"><x-ui.button type="submit">{{ __('Save zone') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Cities and areas')" :subtitle="__('An empty area covers the whole city.')" flush>
        <x-ui.table :headers="[__('City'), __('Area'), '']" :rows="$zone->areas->isNotEmpty()">
            @foreach ($zone->areas as $area)
                <tr class="border-t border-line">
                    <td class="px-5 py-2">{{ $area->city }}</td>
                    <td class="px-5 py-2">{{ $area->area ?? __('Whole city') }}</td>
                    <td class="px-5 py-2 text-end">
                        @if ($canConfigure)
                            <form method="POST" action="{{ route('admin.delivery.areas.destroy', $area->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($canConfigure)
            <form method="POST" action="{{ route('admin.delivery.zones.areas.store', $zone->id) }}" class="grid gap-3 p-5 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('City')" name="city" required />
                <x-ui.input :label="__('Area')" name="area" />
                <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Add area') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Rates')" :subtitle="__('Base fee covers the base weight; each started kg above it adds the per-kg fee.')">
        <div class="flex flex-col gap-4">
            @foreach ($zone->rates as $rate)
                <form method="POST" action="{{ route('admin.delivery.rates.update', $rate->id) }}" class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-4" data-rate="{{ $rate->id }}">
                    @csrf
                    @method('PUT')
                    <x-ui.select :label="__('Method')" name="method" :id="'method-'.$rate->id" :selected="$rate->method->value"
                                 :options="collect($methods)->mapWithKeys(fn ($method) => [$method->value => str_replace('_', ' ', ucfirst($method->value))])->all()" />
                    <x-ui.input :label="__('Base fee')" name="base_fee" :id="'base_fee-'.$rate->id" :value="$rate->base_fee" />
                    <x-ui.input :label="__('Base weight (kg)')" name="base_weight_kg" :id="'base_weight_kg-'.$rate->id" :value="$rate->base_weight_kg" />
                    <x-ui.input :label="__('Per extra kg')" name="per_kg_fee" :id="'per_kg_fee-'.$rate->id" :value="$rate->per_kg_fee" />
                    <x-ui.input :label="__('Transit min days')" name="transit_min_days" :id="'transit_min_days-'.$rate->id" type="number" :value="$rate->transit_min_days" />
                    <x-ui.input :label="__('Transit max days')" name="transit_max_days" :id="'transit_max_days-'.$rate->id" type="number" :value="$rate->transit_max_days" />
                    <x-ui.input :label="__('Cut-off (:zone)', ['zone' => $country->timezone])" name="cutoff_time" :id="'cutoff_time-'.$rate->id" type="time" :value="$rate->cutoff_time ? substr($rate->cutoff_time, 0, 5) : ''" />
                    <div class="flex items-end justify-between gap-2">
                        <x-ui.checkbox :label="__('Active')" name="is_active" :id="'is_active-'.$rate->id" :checked="$rate->is_active" />
                        @if ($canConfigure)<x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>@endif
                    </div>
                </form>
                @if ($canConfigure)
                    <form method="POST" action="{{ route('admin.delivery.rates.destroy', $rate->id) }}" class="-mt-3 text-end">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove :method rate', ['method' => str_replace('_', ' ', $rate->method->value)]) }}</x-ui.button>
                    </form>
                @endif
            @endforeach

            @if ($canConfigure)
                <form method="POST" action="{{ route('admin.delivery.zones.rates.store', $zone->id) }}" class="grid gap-3 rounded-lg border border-dashed border-line-strong p-4 sm:grid-cols-4">
                    @csrf
                    <x-ui.select :label="__('Method')" name="method" :id="'method-new'"
                                 :options="collect($methods)->mapWithKeys(fn ($method) => [$method->value => str_replace('_', ' ', ucfirst($method->value))])->all()" />
                    <x-ui.input :label="__('Base fee')" name="base_fee" id="base_fee-new" required />
                    <x-ui.input :label="__('Base weight (kg)')" name="base_weight_kg" id="base_weight_kg-new" value="0" required />
                    <x-ui.input :label="__('Per extra kg')" name="per_kg_fee" id="per_kg_fee-new" value="0" required />
                    <x-ui.input :label="__('Transit min days')" name="transit_min_days" id="transit_min_days-new" type="number" value="1" required />
                    <x-ui.input :label="__('Transit max days')" name="transit_max_days" id="transit_max_days-new" type="number" value="3" required />
                    <x-ui.input :label="__('Cut-off (:zone)', ['zone' => $country->timezone])" name="cutoff_time" id="cutoff_time-new" type="time" />
                    <div class="flex items-end justify-between gap-2">
                        <x-ui.checkbox :label="__('Active')" name="is_active" id="is_active-new" :checked="true" />
                        <x-ui.button type="submit" size="sm">{{ __('Add rate') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    </x-ui.card>
</x-layouts.panel>
