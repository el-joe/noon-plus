<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Catalog sections') }}">
    @foreach ([['admin.catalog.categories', __('Categories'), 'categories.view'], ['admin.catalog.brands', __('Brands'), 'brands.view'], ['admin.catalog.products', __('Products'), 'products.view'], ['admin.catalog.warranty-plans', __('Warranty plans'), 'warranty_plans.view']] as [$routeName, $label, $ability])
        @can($ability)
            <a href="{{ route($routeName) }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs($routeName.'*'), 'text-fg-secondary' => ! request()->routeIs($routeName.'*')])>{{ $label }}</a>
        @endcan
    @endforeach
</nav>
