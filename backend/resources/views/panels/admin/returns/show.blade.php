<x-layouts.panel :title="__('Return :number', ['number' => $return->number])"
                 :subtitle="__('Shipment :shipment · :seller', ['shipment' => $return->subOrder->number, 'seller' => $return->vendor->store_name])"
                 :breadcrumbs="[__('Orders & disputes'), __('Returns'), $return->number]">
    @include('panels.shared.return-detail')
</x-layouts.panel>
