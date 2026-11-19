<x-layouts.panel :title="$supply->name_en" :subtitle="__('Packaging item in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Packaging'), $supply->sku]">
    <x-ui.card>
        <form method="POST" action="{{ route('admin.packaging.supplies.update', $supply->id) }}" class="grid gap-3 sm:grid-cols-3">
            @csrf
            @method('PUT')
            @include('panels.admin.packaging._supply-fields', ['supply' => $supply])
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
