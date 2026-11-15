<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Staff sections') }}">
    @can('admins.view')<a href="{{ route('admin.staff') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.staff*'), 'text-fg-secondary' => ! request()->routeIs('admin.staff*')])>{{ __('Staff') }}</a>@endcan
    @can('roles.view')<a href="{{ route('admin.roles') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.roles*'), 'text-fg-secondary' => ! request()->routeIs('admin.roles*')])>{{ __('Roles & permissions') }}</a>@endcan
</nav>
