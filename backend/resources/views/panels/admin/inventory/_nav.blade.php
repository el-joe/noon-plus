@php($current = request()->route()?->getName())
<nav class="flex flex-wrap gap-4 border-b border-line text-sm" aria-label="{{ __('Inventory sections') }}">
    @foreach ([
        'admin.inventory' => __('Overview'),
        'admin.inventory.inbound' => __('Receiving'),
        'admin.inventory.pick-pack' => __('Pick & pack'),
        'admin.inventory.stock' => __('Stock & adjustments'),
        'admin.inventory.transfers' => __('Transfers'),
        'admin.inventory.removals' => __('Removals'),
        'admin.inventory.warehouses' => __('Warehouses'),
        'admin.inventory.fees' => __('FBN fees'),
    ] as $route => $label)
        <a href="{{ route($route) }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $current === $route || ($route !== 'admin.inventory' && str_starts_with((string) $current, $route.'.')), 'text-fg-secondary' => $current !== $route])>{{ $label }}</a>
    @endforeach
</nav>
