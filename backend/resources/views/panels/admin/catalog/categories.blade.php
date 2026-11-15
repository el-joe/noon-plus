<x-layouts.panel :title="__('Categories')" :subtitle="__('Category tree in :country · bilingual names, commission and attributes', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Categories')]">
    <x-slot:actions>
        @can('categories.manage')
            <x-ui.button :href="route('admin.catalog.categories.create')">{{ __('New category') }}</x-ui.button>
        @endcan
    </x-slot:actions>

    @include('panels.admin.catalog._nav')

    <form method="GET" action="{{ route('admin.catalog.categories') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Name or slug')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Category'), __('Slug'), __('Commission'), __('Products'), __('Status')]" :rows="$categories->isNotEmpty()" :empty="__('No categories yet. Create one or copy them from another country.')">
            @foreach ($categories as $category)
                @include('panels.admin.catalog._category-row', ['category' => $category, 'depth' => 0, 'nested' => ! $flat])
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $categories->links() }}

    @can('categories.manage')
        <x-ui.card :title="__('Copy categories to other countries')" :subtitle="__('Copies each category with its sub-categories, attributes and measurement fields. Existing categories with the same slug are updated, never duplicated.')">
            @if ($copyTargets->isEmpty() || $roots->isEmpty())
                <p class="text-sm text-fg-secondary">{{ __('Nothing to copy, or no other country where you manage categories.') }}</p>
            @else
                <form method="POST" action="{{ route('admin.catalog.categories.copy') }}" class="grid gap-4 sm:grid-cols-2" data-category-copy>
                    @csrf
                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-sm font-medium">{{ __('Categories (with sub-categories)') }}</legend>
                        @foreach ($roots as $root)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="category_ids[]" value="{{ $root->id }}" class="size-4 accent-brand"> {{ $root->name_en }}</label>
                        @endforeach
                        @error('category_ids')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                    </fieldset>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-sm font-medium">{{ __('To countries') }}</legend>
                        @foreach ($copyTargets as $target)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="countries[]" value="{{ $target->code }}" class="size-4 accent-brand"> {{ $target->name_en }}</label>
                        @endforeach
                        @error('countries')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                        <x-ui.checkbox :label="__('Also copy commission rates')" name="with_commission" />
                    </fieldset>
                    <div><x-ui.button type="submit">{{ __('Copy categories') }}</x-ui.button></div>
                </form>
            @endif
        </x-ui.card>
    @endcan
</x-layouts.panel>
