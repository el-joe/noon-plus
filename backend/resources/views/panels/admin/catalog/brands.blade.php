<x-layouts.panel :title="__('Brands')" :subtitle="__('Brands in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Brands')]">
    @include('panels.admin.catalog._nav')

    <form method="GET" action="{{ route('admin.catalog.brands') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Brand'), __('Slug'), __('Products'), __('Status'), '']" :rows="$brands->isNotEmpty()" :empty="__('No brands yet.')">
            @foreach ($brands as $brand)
                <tr class="border-t border-line" data-brand="{{ $brand->id }}">
                    <td class="px-5 py-3 font-medium">{{ $brand->name }} @if ($brand->name_ar)<span class="text-fg-secondary" dir="rtl">· {{ $brand->name_ar }}</span>@endif</td>
                    <td class="px-5 py-3 text-xs">{{ $brand->slug }}</td>
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.catalog.products', ['brand' => $brand->id]) }}">{{ $brand->products_count }}</a></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$brand->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@can('brands.manage')<x-ui.button variant="ghost" size="sm" :href="route('admin.catalog.brands.edit', $brand->id)">{{ __('Edit') }}</x-ui.button>@endcan</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $brands->links() }}

    @can('brands.manage')
        <x-ui.card :title="__('Add a brand')">
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.catalog.brands.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Name (Arabic)')" name="name_ar" dir="rtl" />
                <x-ui.input :label="__('Slug')" name="slug" required />
                <div class="flex flex-col gap-1.5"><label for="logo" class="text-sm font-medium">{{ __('Logo') }}</label><input id="logo" type="file" name="logo" accept="image/*" class="text-sm">@error('logo')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror</div>
                <div><x-ui.button type="submit">{{ __('Add brand') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
