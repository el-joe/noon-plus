@php($current = request()->route()?->getName())
<nav class="flex flex-wrap gap-4 border-b border-line text-sm" aria-label="{{ __('Inventory sections') }}">
    @foreach ([
        'partner.inventory' => __('FBN stock'),
        'partner.inventory.inbound' => __('Inbound plans'),
        'partner.inventory.removals' => __('Removals'),
        'partner.inventory.fees' => __('FBN fees'),
    ] as $route => $label)
        <a href="{{ route($route) }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $current === $route || ($route !== 'partner.inventory' && str_starts_with((string) $current, $route.'.')), 'text-fg-secondary' => $current !== $route])>{{ $label }}</a>
    @endforeach
</nav>
