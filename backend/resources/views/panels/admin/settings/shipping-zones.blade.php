<x-layouts.panel :title="__('Shipping zones & rates')" :subtitle="__(':country · zones group cities and areas; each zone has one rate per delivery method', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Delivery & logistics'), __('Shipping zones')]">
    @include('panels.admin.delivery._nav')
    <x-ui.card flush>
        <x-ui.table :headers="[__('Zone'), __('Areas'), __('Rates'), __('Flags'), '']" :rows="$zones->isNotEmpty()" :empty="__('No zones yet — every order uses the flat fallback fee.')">
            @foreach ($zones as $zone)
                <tr class="border-t border-line" data-zone="{{ $zone->id }}">
                    <td class="px-5 py-3 font-medium">{{ $zone->name }}</td>
                    <td class="px-5 py-3">{{ $zone->areas_count }}</td>
                    <td class="px-5 py-3">{{ $zone->rates_count }}</td>
                    <td class="flex flex-wrap gap-1 px-5 py-3">
                        @if ($zone->is_default)<x-ui.badge tone="brand">{{ __('Default') }}</x-ui.badge>@endif
                        @if ($zone->is_exceptional)<x-ui.badge tone="info">{{ __('Exceptional') }}</x-ui.badge>@endif
                        @unless ($zone->is_active)<x-ui.badge tone="danger">{{ __('Inactive') }}</x-ui.badge>@endunless
                    </td>
                    <td class="px-5 py-3 text-end"><a class="text-fg-link" href="{{ route('admin.delivery.zones.show', $zone->id) }}">{{ __('Open') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('shipping_zones.configure')
        <x-ui.card :title="__('New zone')">
            <form method="POST" action="{{ route('admin.delivery.zones.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Seller share of the subsidy (%)')" name="vendor_subsidy_share_percent" value="0" required />
                <div class="flex flex-col justify-end gap-2">
                    <x-ui.checkbox :label="__('Default zone')" name="is_default" />
                    <x-ui.checkbox :label="__('Exceptional (subsidised) zone')" name="is_exceptional" />
                    <x-ui.checkbox :label="__('Active')" name="is_active" :checked="true" />
                </div>
                <div class="sm:col-span-3"><x-ui.button type="submit">{{ __('Create zone') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
