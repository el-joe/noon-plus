<x-layouts.panel :title="$order->number" :subtitle="__('Packaging order')" :breadcrumbs="[__('Inventory & warehouses'), __('Packaging supplies'), $order->number]">
    <x-slot:actions><x-ui.status-badge :status="$order->status" /></x-slot:actions>
    @include('panels.partner.packaging._order')
    <x-ui.state-timeline :title="__('Order history')"
                         :steps="$order->transitions->map(fn ($transition) => [
                             'label' => __(\App\Shared\Localization\Label::key($transition->to_status)),
                             'meta' => $transition->created_at?->format('j M H:i').($transition->reason ? ' · '.$transition->reason : ''),
                             'state' => $transition->to_status === 'rejected' ? 'stopped' : 'done',
                         ])->all()" />
</x-layouts.panel>
