<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Acquisition sections') }}">
    @can('acquisition_commissions.view')
        <a href="{{ route('admin.finance.acquisition-commissions') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.acquisition-commissions*'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.acquisition-commissions*')])>{{ __('Commissions') }}</a>
        <a href="{{ route('admin.finance.acquisition-rules') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.acquisition-rules*'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.acquisition-rules*')])>{{ __('Rules') }}</a>
    @endcan
    <a href="{{ route('admin.finance.my-acquisition-commissions') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.finance.my-acquisition-commissions'), 'text-fg-secondary' => ! request()->routeIs('admin.finance.my-acquisition-commissions')])>{{ __('My acquisition commissions') }}</a>
</nav>
