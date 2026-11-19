<x-layouts.panel :title="__('Warehouses')" :subtitle="__(':country · Fulfilled by Nawy warehouses', ['country' => $country->name_en])" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Warehouses')]">
    @include('panels.admin.inventory._nav')

    @foreach ($warehouses as $warehouse)
        <x-ui.card :title="$warehouse->code.' · '.$warehouse->name_en" :subtitle="__(':units units on hand', ['units' => (int) $warehouse->stock_sum_on_hand])">
            @can('fbn.configure')
                <form method="POST" action="{{ route('admin.inventory.warehouses.update', $warehouse->id) }}" class="grid gap-4 px-5 pb-5 sm:grid-cols-4" data-warehouse-form="{{ $warehouse->id }}">
                    @csrf @method('PUT')
                    @include('panels.admin.inventory._warehouse-fields', ['warehouse' => $warehouse, 'prefix' => 'w'.$warehouse->id])
                    <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                </form>
            @else
                <p class="px-5 pb-5 text-sm">{{ $warehouse->address }} {{ $warehouse->city }} · {{ $warehouse->is_active ? __('Active') : __('Inactive') }}</p>
            @endcan
        </x-ui.card>
    @endforeach

    @can('fbn.configure')
        <x-ui.card :title="__('Add a warehouse')">
            <form method="POST" action="{{ route('admin.inventory.warehouses.store') }}" class="grid gap-4 px-5 pb-5 sm:grid-cols-4">
                @csrf
                @include('panels.admin.inventory._warehouse-fields', ['warehouse' => null, 'prefix' => 'new'])
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Add warehouse') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
