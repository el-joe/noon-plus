<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Vendor subscription sections') }}">
    <a href="{{ route('admin.finance.subscriptions') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.subscriptions*'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.subscriptions*')])>{{ __('Subscriptions') }}</a>
    <a href="{{ route('admin.finance.subscription-plans') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.subscription-plans*'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.subscription-plans*')])>{{ __('Plans') }}</a>
</nav>
