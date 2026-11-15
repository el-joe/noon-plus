<x-layouts.panel :title="__('Products')" :subtitle="__('Your listings in this country — price, stock and status')" :breadcrumbs="[__('Seller Center'), __('Products')]">
    <x-slot:actions>
        <x-ui.button :href="route('partner.products.create')">{{ __('Add a product') }}</x-ui.button>
    </x-slot:actions>

    <form method="GET" action="{{ route('partner.products') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Title, SKU or your SKU')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Listings\Enums\ListingStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <x-ui.select :label="__('Stock')" name="stock" :selected="$filters['stock'] ?? ''" :placeholder="__('Any stock')"
                     :options="['in' => __('In stock'), 'low' => __('Low (1–5)'), 'out' => __('Out of stock')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Product'), __('SKU'), __('Price'), __('Stock'), __('Fulfilment'), __('Status'), '']" :rows="$listings->isNotEmpty()" :empty="__('No listings match these filters.')">
            @foreach ($listings as $listing)
                <tr class="border-t border-line" data-listing="{{ $listing->id }}">
                    <td class="px-5 py-3">
                        <span class="font-medium">{{ $listing->variant->product->title() }}</span>
                        <span class="block text-xs text-fg-secondary">{{ ucfirst($listing->condition) }}</span>
                    </td>
                    <td class="px-5 py-3 text-xs">{{ $listing->vendor_sku ?? $listing->variant->sku }}</td>
                    <td class="px-5 py-3">
                        <x-ui.money :amount="$listing->effectivePrice()" :currency="$currency" />
                        @if ($listing->sale_price)
                            <span class="block text-xs text-fg-muted line-through"><x-ui.money :amount="$listing->price" :currency="$currency" /></span>
                        @endif
                    </td>
                    <td class="px-5 py-3">{{ (int) $listing->available_quantity }}</td>
                    <td class="px-5 py-3 uppercase">{{ $listing->fulfilment }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$listing->status" /></td>
                    <td class="px-5 py-3 text-end"><a class="text-fg-link" href="{{ route('partner.products.edit', $listing->id) }}">{{ __('Manage') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $listings->links() }}
</x-layouts.panel>
