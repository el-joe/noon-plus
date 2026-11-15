<x-layouts.panel :title="$refund->number" :subtitle="__('Refund in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Refunds'), $refund->number]">
    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Amount')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $refund->amount, $refund->currency)" />
        <x-ui.stat-card :label="__('Still refundable on the order')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $refundable->getAmount(), $refund->currency)" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5"><span class="text-sm text-fg-secondary">{{ __('Status') }}</span><x-ui.status-badge :status="$refund->status" class="self-start" /></div>
    </section>

    <x-ui.card :title="__('Details')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Order') }}</dt><dd>@can('orders.view')<a class="text-fg-link" href="{{ route('admin.orders.show', $refund->order_id) }}">{{ $refund->order?->number }}</a>@else {{ $refund->order?->number }} @endcan</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Shipment') }}</dt><dd>{{ $refund->subOrder?->number ?? __('Whole order') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Source') }}</dt><dd>{{ str_replace('_', ' ', ucfirst($refund->source_type->value)) }}{{ $refund->source_id ? ' #'.$refund->source_id : '' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Destination') }}</dt><dd>{{ $refund->destination === \App\Domains\Refunds\Enums\RefundDestination::Wallet ? __('Nawy Credits') : __('Original payment method') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Reason') }}</dt><dd>{{ $refund->reason ?? '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('After settlement') }}</dt><dd>{{ $refund->after_settlement ? __('Yes — clawed back from the seller') : __('No') }}</dd></div>
            @if ($refund->failure_message)
                <div class="sm:col-span-3"><dt class="text-fg-secondary">{{ __('Last failure') }}</dt><dd class="text-fg-danger">{{ $refund->failure_message }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($refund->status === \App\Domains\Refunds\Enums\RefundStatus::Pending)
        <x-ui.card :title="__('Decide')">
            <div class="flex flex-wrap items-end gap-6">
                <form method="POST" action="{{ route('admin.refunds.approve', $refund->id) }}" class="flex items-end gap-3">
                    @csrf
                    <x-ui.input :label="__('Note (optional)')" name="note" />
                    <x-ui.button type="submit">{{ __('Approve and pay out') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.refunds.reject', $refund->id) }}" class="flex items-end gap-3">
                    @csrf
                    <x-ui.input :label="__('Rejection reason')" name="reason" required />
                    <x-ui.button type="submit" variant="danger">{{ __('Reject') }}</x-ui.button>
                </form>
            </div>
        </x-ui.card>
    @elseif ($refund->status === \App\Domains\Refunds\Enums\RefundStatus::Failed)
        <x-ui.card :title="__('Retry')">
            <form method="POST" action="{{ route('admin.refunds.retry', $refund->id) }}">@csrf<x-ui.button type="submit">{{ __('Retry the refund') }}</x-ui.button></form>
        </x-ui.card>
    @endif

    @include('panels.admin._partials.transitions', ['transitions' => $refund->transitions])
</x-layouts.panel>
