@php($status = \App\Domains\Fulfilment\Enums\PackagingOrderStatus::class)
<x-layouts.panel :title="$order->number" :subtitle="$order->vendor?->store_name" :breadcrumbs="[__('Operations'), __('Packaging orders'), $order->number]">
    <x-slot:actions><x-ui.status-badge :status="$order->status" /></x-slot:actions>
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">@include('panels.partner.packaging._order')</div>
        @can('packaging.manage')
            <div class="flex flex-col gap-6">
                @if ($order->status === $status::Pending)
                    <x-ui.card :title="__('Decision')">
                        <form method="POST" action="{{ route('admin.packaging.orders.move', [$order->id, 'approve']) }}" class="mb-4">
                            @csrf
                            <x-ui.button type="submit">{{ __('Approve') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.packaging.orders.move', [$order->id, 'reject']) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.textarea :label="__('Reason (the charge is given back)')" name="reason" :rows="2" required />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Reject') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                @elseif ($order->status === $status::Approved)
                    <x-ui.card :title="__('Ship')">
                        <form method="POST" action="{{ route('admin.packaging.orders.move', [$order->id, 'ship']) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.input :label="__('Carrier')" name="carrier_name" />
                            <x-ui.input :label="__('Tracking number')" name="tracking_number" />
                            <div><x-ui.button type="submit">{{ __('Mark shipped') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                @elseif ($order->status === $status::Shipped)
                    <x-ui.card :title="__('Delivery')">
                        <form method="POST" action="{{ route('admin.packaging.orders.move', [$order->id, 'deliver']) }}">
                            @csrf
                            <x-ui.button type="submit">{{ __('Mark delivered') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            </div>
        @endcan
    </div>
    @include('panels.admin._partials.transitions', ['transitions' => $order->transitions])
</x-layouts.panel>
