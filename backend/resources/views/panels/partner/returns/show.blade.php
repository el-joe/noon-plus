<x-layouts.panel :title="__('Return :number', ['number' => $return->number])"
                 :subtitle="__('Shipment :shipment', ['shipment' => $return->subOrder->number])"
                 :breadcrumbs="[__('Seller Center'), __('Returns'), $return->number]">
    @include('panels.shared.return-detail')
</x-layouts.panel>
