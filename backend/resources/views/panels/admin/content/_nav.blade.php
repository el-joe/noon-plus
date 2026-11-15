@php($current = (string) request()->route()?->getName())
<nav class="flex flex-wrap gap-4 border-b border-line text-sm" aria-label="{{ __('Content sections') }}">
    @foreach ([
        'admin.pages' => [__('Pages'), 'pages.view'],
        'admin.app-contexts' => [__('App contexts'), 'app_contexts.manage'],
        'admin.media' => [__('Live & videos'), 'live_media.view'],
        'admin.cms' => [__('Blog'), 'blog.manage'],
        'admin.cms.help' => [__('Help centre & knowledge hub'), 'help_center.manage'],
        'admin.cms.static' => [__('Static pages'), 'static_pages.manage'],
        'admin.cms.footer' => [__('Footer'), 'footer.manage'],
        'admin.notification-templates' => [__('Notification templates'), 'notification_templates.manage'],
    ] as $route => [$label, $ability])
        @can($ability)
            <a href="{{ route($route) }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $current === $route, 'text-fg-secondary' => $current !== $route])>{{ $label }}</a>
        @endcan
    @endforeach
</nav>
