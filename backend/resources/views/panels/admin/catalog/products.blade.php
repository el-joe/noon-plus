<x-layouts.panel :title="__('Products')" :subtitle="__('Catalogue products in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Products')]">
    @include('panels.admin.catalog._nav')

    <form method="GET" action="{{ route('admin.catalog.products') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-5" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Title, slug or SKU')" />
        <x-ui.select :label="__('Category')" name="category" :selected="$filters['category'] ?? ''" :placeholder="__('All')" :options="$categories" />
        <x-ui.select :label="__('Brand')" name="brand" :selected="$filters['brand'] ?? ''" :placeholder="__('All')" :options="$brands" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Product'), __('Category'), __('Brand'), __('Variants'), __('Status')]" :rows="$products->isNotEmpty()" :empty="__('No products.')">
            @foreach ($products as $product)
                <tr class="border-t border-line" data-product="{{ $product->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.catalog.products.show', $product->id) }}">{{ $product->title_en }}</a><span class="block text-xs text-fg-secondary" dir="rtl">{{ $product->title_ar }}</span></td>
                    <td class="px-5 py-3">{{ $product->category?->name_en }}</td>
                    <td class="px-5 py-3">{{ $product->brand?->name ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $product->variants_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$product->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $products->links() }}
</x-layouts.panel>
