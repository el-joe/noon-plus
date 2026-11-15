<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Refund sections') }}">
    @can('orders.refund')
        <a href="{{ route('admin.refunds') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.refunds*'), 'text-fg-secondary' => ! request()->routeIs('admin.refunds*')])>{{ __('Refunds') }}</a>
    @endcan
    @can('payments.review')
        <a href="{{ route('admin.finance.payments-review') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.payments-review*'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.payments-review*')])>{{ __('Payments under review') }}</a>
    @endcan
</nav>
