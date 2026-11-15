@php($current = request()->route()?->getName())
<nav class="flex flex-wrap gap-4 border-b border-line text-sm" aria-label="{{ __('Delivery sections') }}">
    @foreach ([
        'admin.delivery' => [__('Zones & rates'), 'shipping_zones.view'],
        'admin.delivery.assignments' => [__('Dispatch'), 'delivery_assignments.view'],
        'admin.delivery.agents' => [__('Agents'), 'delivery_agents.manage'],
        'admin.delivery.carriers' => [__('Shipping companies'), 'shipping_companies.manage'],
        'admin.delivery.claims' => [__('Carrier claims'), 'carrier_claims.view'],
        'admin.delivery.scorecard' => [__('Scorecard'), 'delivery_assignments.view'],
    ] as $route => [$label, $ability])
        @can($ability)
            <a href="{{ route($route) }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $current === $route || str_starts_with((string) $current, $route.'.show'), 'text-fg-secondary' => $current !== $route])>{{ $label }}</a>
        @endcan
    @endforeach
</nav>
