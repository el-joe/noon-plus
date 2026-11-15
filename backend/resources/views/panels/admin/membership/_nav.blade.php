<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Nawy One sections') }}">
    <a href="{{ route('admin.membership') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => ! request()->routeIs('admin.membership.subscriptions*'), 'text-fg-secondary' => request()->routeIs('admin.membership.subscriptions*')])>{{ __('Plans') }}</a>
    <a href="{{ route('admin.membership.subscriptions') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.membership.subscriptions*'), 'text-fg-secondary' => ! request()->routeIs('admin.membership.subscriptions*')])>{{ __('Subscriptions') }}</a>
</nav>
