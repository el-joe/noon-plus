<x-layouts.panel :title="$product->title_en" :subtitle="__('Product in :country · :slug', ['country' => $country->name_en, 'slug' => $product->slug])" :breadcrumbs="[__('Catalog'), __('Products'), $product->title_en]">
    <x-slot:actions>
        @if ($canUpdate)
            <x-ui.button :href="route('admin.catalog.products.edit', $product->id)">{{ __('Edit product') }}</x-ui.button>
        @endif
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Details')" class="lg:col-span-2">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Title (Arabic)') }}</dt><dd dir="rtl">{{ $product->title_ar }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$product->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Category') }}</dt><dd><a class="text-fg-link" href="{{ route('admin.catalog.categories.show', $product->category_id) }}">{{ $product->category?->name_en }}</a></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Brand') }}</dt><dd>{{ $product->brand?->name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Fulfilment') }}</dt><dd>{{ str_replace('_', ' ', ucfirst($product->fulfilment_mode)) }}{{ $product->lead_days ? ' · '.trans_choice(':count day|:count days', $product->lead_days) : '' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Rating') }}</dt><dd>{{ $product->rating_average ?? '—' }} ({{ $product->rating_count ?? 0 }})</dd></div>
                <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Description') }}</dt><dd class="whitespace-pre-line">{{ $product->description_en ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('Images')">
            <div class="grid grid-cols-3 gap-2">
                @forelse ($product->media->take(6) as $media)
                    <img src="{{ $media->url() }}" alt="{{ $media->alt_en ?? $product->title_en }}" class="aspect-square rounded-lg object-cover">
                @empty
                    <p class="col-span-3 text-sm text-fg-secondary">{{ __('No images.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Variants')" flush>
        <x-ui.table :headers="[__('SKU'), __('Options'), __('Barcode'), __('Weight (g)'), '']" :rows="$product->variants->isNotEmpty()">
            @foreach ($product->variants as $variant)
                <tr class="border-t border-line" data-variant="{{ $variant->id }}">
                    @if ($canUpdate)
                        <td colspan="5" class="px-5 py-2">
                            <form method="POST" action="{{ route('admin.catalog.variants.update', $variant->id) }}" class="grid items-end gap-3 sm:grid-cols-5">
                                @csrf @method('PUT')
                                <x-ui.input :label="__('SKU')" name="sku" :value="$variant->sku" :id="'sku-'.$variant->id" />
                                <div class="text-xs text-fg-secondary">{{ collect($variant->option_values ?? [])->map(fn ($value, $key) => is_scalar($value) ? $key.': '.$value : $key)->implode(', ') ?: '—' }}</div>
                                <x-ui.input :label="__('Barcode')" name="barcode" :value="$variant->barcode" :id="'barcode-'.$variant->id" />
                                <x-ui.input :label="__('Weight (g)')" name="weight_grams" type="number" :value="$variant->weight_grams" :id="'weight-'.$variant->id" />
                                <div><x-ui.button type="submit" variant="outline" size="sm">{{ __('Save') }}</x-ui.button></div>
                            </form>
                        </td>
                    @else
                        <td class="px-5 py-3 font-mono text-xs">{{ $variant->sku }}</td>
                        <td class="px-5 py-3 text-xs">{{ collect($variant->option_values ?? [])->map(fn ($value, $key) => is_scalar($value) ? $key.': '.$value : $key)->implode(', ') ?: '—' }}</td>
                        <td class="px-5 py-3">{{ $variant->barcode ?? '—' }}</td>
                        <td class="px-5 py-3">{{ $variant->weight_grams ?? '—' }}</td>
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Seller offers')" :subtitle="__('Offers pending review are decided in the Approvals queue; an inactive product cannot have offers approved.')" flush>
        <x-ui.table :headers="[__('Seller'), __('SKU'), __('Price'), __('Status')]" :rows="$listings->isNotEmpty()" :empty="__('No offers yet.')">
            @foreach ($listings as $listing)
                <tr class="border-t border-line" data-listing="{{ $listing->id }}">
                    <td class="px-5 py-3">{{ $listing->vendor?->store_name }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $listing->variant?->sku }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $listing->price" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">
                        <x-ui.status-badge :status="$listing->status" />
                        @if ($listing->status === \App\Domains\Listings\Enums\ListingStatus::PendingReview)
                            @can('vendor_listings.view')<a class="ms-2 text-xs text-fg-link" href="{{ route('admin.approvals') }}">{{ __('Review') }}</a>@endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
