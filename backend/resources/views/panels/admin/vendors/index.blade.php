<x-layouts.panel :title="__('Vendors')" :subtitle="$restricted ? __('Your assigned sellers in :country', ['country' => $country->name_en]) : __('Sellers in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Users & vendors'), __('Vendors')]">
    <form method="GET" action="{{ route('admin.vendors') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-5" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Store name')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="['active' => __('Active'), 'suspended' => __('Suspended'), 'pending' => __('Pending')]" />
        <x-ui.select :label="__('Type')" name="type" :selected="$filters['type'] ?? ''" :placeholder="__('All')" :options="['product' => __('Product seller'), 'classified' => __('Classified seller')]" />
        @unless ($restricted)
            <x-ui.select :label="__('Account manager')" name="manager" :selected="$filters['manager'] ?? ''" :placeholder="__('Any')" :options="$managers->pluck('name', 'id')->all()" />
        @endunless
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Seller'), __('Type'), __('Listings'), __('Account manager'), __('Status')]" :rows="$vendors->isNotEmpty()" :empty="__('No sellers.')">
            @foreach ($vendors as $vendor)
                <tr class="border-t border-line" data-vendor="{{ $vendor->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.vendors.show', $vendor->id) }}">{{ $vendor->store_name }}</a><span class="block text-xs text-fg-secondary">V-{{ $vendor->id }}</span></td>
                    <td class="px-5 py-3">{{ ucfirst($vendor->type) }}</td>
                    <td class="px-5 py-3">{{ $vendor->listings_count }}</td>
                    <td class="px-5 py-3">{{ $managers->firstWhere('id', $vendor->account_manager_id)?->name ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$vendor->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $vendors->links() }}
</x-layouts.panel>
