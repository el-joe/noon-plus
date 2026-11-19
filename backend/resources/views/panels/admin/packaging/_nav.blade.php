<nav class="flex gap-2" aria-label="{{ __('Packaging') }}">
    <x-ui.button size="sm" :variant="request()->routeIs('admin.packaging') ? 'primary' : 'outline'" :href="route('admin.packaging')">{{ __('Catalogue') }}</x-ui.button>
    <x-ui.button size="sm" :variant="request()->routeIs('admin.packaging.orders*') ? 'primary' : 'outline'" :href="route('admin.packaging.orders')">{{ __('Packaging orders') }}</x-ui.button>
</nav>
