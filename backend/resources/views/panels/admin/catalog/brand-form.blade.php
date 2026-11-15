<x-layouts.panel :title="__('Edit :name', ['name' => $brand->name])" :subtitle="__('Brand in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Brands'), __('Edit')]">
    <x-ui.card>
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.catalog.brands.update', $brand->id) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf @method('PUT')
            <x-ui.input :label="__('Name')" name="name" :value="$brand->name" required />
            <x-ui.input :label="__('Name (Arabic)')" name="name_ar" :value="$brand->name_ar" dir="rtl" />
            <x-ui.input :label="__('Slug')" name="slug" :value="$brand->slug" required />
            <div class="flex flex-col gap-1.5"><label for="logo" class="text-sm font-medium">{{ __('Replace logo') }}</label><input id="logo" type="file" name="logo" accept="image/*" class="text-sm">@error('logo')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror</div>
            <x-ui.checkbox :label="__('Active')" name="is_active" :checked="$brand->is_active" />
            <div class="flex gap-2 sm:col-span-2"><x-ui.button type="submit">{{ __('Save brand') }}</x-ui.button><x-ui.button variant="ghost" :href="route('admin.catalog.brands')">{{ __('Cancel') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
