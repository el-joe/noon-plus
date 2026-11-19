@php($editing = $category->exists)
<x-layouts.panel :title="$editing ? __('Edit :name', ['name' => $category->name_en]) : __('New category')" :subtitle="__('Category in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Categories'), $editing ? __('Edit') : __('New')]">
    <form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('admin.catalog.categories.update', $category->id) : route('admin.catalog.categories.store') }}" class="flex flex-col gap-6" data-category-form>
        @csrf
        @if ($editing) @method('PUT') @endif
        <x-ui.card :title="__('Names and place in the tree')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input :label="__('Name (English)')" name="name_en" :value="$category->name_en" required />
                <x-ui.input :label="__('Name (Arabic)')" name="name_ar" :value="$category->name_ar" dir="rtl" required />
                <x-ui.input :label="__('Slug')" name="slug" :value="$category->slug" :helper="__('Used in links; also how categories match across countries.')" required />
                <x-ui.select :label="__('Parent')" name="parent_id" :selected="$category->parent_id" :placeholder="__('Top level')" :options="$parents->pluck('name_en', 'id')->all()" />
                <x-ui.textarea :label="__('Description (English)')" name="description_en" :value="$category->description_en" />
                <x-ui.textarea :label="__('Description (Arabic)')" name="description_ar" :value="$category->description_ar" dir="rtl" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Commerce rules')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input :label="__('Commission rate (%)')" name="commission_rate" :value="$category->commission_rate" inputmode="decimal" :helper="__('Empty = platform default.')" />
                <x-ui.input :label="__('Return window (days)')" name="return_window_days" type="number" :value="$category->return_window_days" />
                <x-ui.input :label="__('Sort order')" name="sort_order" type="number" :value="$category->sort_order ?? 0" />
                <x-ui.select :label="__('Default size guide')" name="size_guide_template_id" :selected="$category->size_guide_template_id" :placeholder="__('None')" :options="$sizeGuides->pluck('name', 'id')->all()" />
                <div class="flex flex-col justify-end gap-2 sm:col-span-2">
                    <x-ui.checkbox :label="__('Sellers must sign the category contract')" name="requires_contract" :checked="$category->requires_contract" />
                    <x-ui.checkbox :label="__('Sellers need a certification')" name="requires_certification" :checked="$category->requires_certification" />
                    <x-ui.checkbox :label="__('Active (shown on the storefront)')" name="is_active" :checked="$category->is_active ?? true" />
                </div>
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Image')" :subtitle="__('Shown on the category page and, for top-level categories, as the large picture in the header menu.')">
            @if ($category->image_path)
                <div class="mb-4 flex items-center gap-4" data-category-image>
                    <img src="{{ \App\Shared\Http\PublicUrl::of($category->image_path) }}" alt="{{ $category->name_en }}" class="size-24 rounded-lg object-cover">
                    <x-ui.checkbox :label="__('Remove the current image')" name="remove_image" />
                </div>
            @endif
            <div class="flex flex-col gap-1.5">
                <label for="image" class="text-sm font-medium">{{ __('Category image (JPG, PNG or WebP, up to 2 MB)') }}</label>
                <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="text-sm">
                @error('image')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
            </div>
        </x-ui.card>
        <div class="flex gap-2">
            <x-ui.button type="submit">{{ $editing ? __('Save category') : __('Create category') }}</x-ui.button>
            <x-ui.button variant="ghost" :href="$editing ? route('admin.catalog.categories.show', $category->id) : route('admin.catalog.categories')">{{ __('Cancel') }}</x-ui.button>
        </div>
    </form>
</x-layouts.panel>
