<x-layouts.panel :title="__('Add a product')" :subtitle="__('List an existing catalogue product. Some categories need a signed contract or a certificate before you can list.')"
                 :breadcrumbs="[__('Products'), __('Add a product')]">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('1 · Find the product')" :subtitle="__('Missing from the catalogue? Ask us to add it with a product request.')">
                <form method="GET" action="{{ route('partner.products.create') }}" class="flex items-end gap-3" role="search">
                    <div class="grow"><x-ui.input :label="__('Product name, SKU or barcode')" name="q" :value="$search" /></div>
                    <x-ui.button type="submit" variant="outline">{{ __('Search') }}</x-ui.button>
                </form>
                <p class="mt-2 text-sm"><a class="text-fg-link" href="{{ route('partner.product-requests') }}">{{ __('Request a product') }}</a></p>
                @if ($search !== '')
                    <ul class="mt-4 divide-y divide-line rounded-lg border border-line" data-variant-results>
                        @forelse ($matches as $match)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <span>
                                    <span class="font-medium">{{ $match->product->title() }}</span>
                                    <span class="block text-xs text-fg-secondary">{{ $match->sku }} · {{ collect($match->option_values)->implode(' · ') }}</span>
                                </span>
                                <x-ui.button size="sm" variant="outline" :href="route('partner.products.create', ['variant' => $match->id])">{{ __('Sell this') }}</x-ui.button>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-fg-secondary">{{ __('No catalogue product matches.') }}</li>
                        @endforelse
                    </ul>
                @endif
            </x-ui.card>

            @if ($variant)
                <x-ui.card :title="__('2 · Your offer')" :subtitle="$variant->product->title().' · '.$variant->sku.' · '.$variant->product->category->name()">
                    <form method="POST" action="{{ route('partner.products.store') }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="product_variant_id" value="{{ $variant->id }}">
                        @error('product_variant_id')<p class="text-sm text-fg-danger sm:col-span-2">{{ $message }}</p>@enderror
                        <x-ui.input :label="__('Price (VAT included)')" name="price" inputmode="decimal" required />
                        <x-ui.input :label="__('Sale price')" name="sale_price" inputmode="decimal" :helper="__('Optional, below the price')" />
                        <x-ui.select :label="__('Condition')" name="condition" selected="new"
                                     :options="collect(config('nawy.vendors.listing_conditions'))->mapWithKeys(fn ($value) => [$value => ucfirst($value)])->all()" />
                        <x-ui.select :label="__('Fulfilment')" name="fulfilment" selected="fbm"
                                     :options="['fbm' => __('Fulfilled by me (FBM)'), 'fbn' => __('Fulfilled by Nawy (FBN)'), 'cross_dock' => __('Cross-dock')]" />
                        <x-ui.input :label="__('Handling days')" name="handling_days" type="number" min="0" max="30" value="1" required />
                        <x-ui.input :label="__('Opening stock')" name="stock" type="number" min="0" value="0" required :helper="__('FBN listings start at 0: their stock arrives with an inbound plan.')" />
                        <x-ui.input :label="__('Your SKU')" name="vendor_sku" />
                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label for="images" class="text-sm font-medium text-fg">{{ __('Product images') }}</label>
                            <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                                   class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                            <p class="text-xs text-fg-secondary">{{ __('Optional · JPG, PNG or WebP up to 5 MB each, up to :count images; shown on the product page in this order.', ['count' => \App\Domains\Catalog\Support\ProductImages::MAX_PER_VENDOR_PRODUCT]) }}</p>
                            @error('images')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                            @error('images.*')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                        </div>
                        <x-ui.input :label="__('Image alt text (English)')" name="image_alt_en" />
                        <x-ui.input :label="__('Image alt text (Arabic)')" name="image_alt_ar" dir="rtl" />
                        <div class="flex items-end justify-end gap-2 sm:col-span-2">
                            <x-ui.button type="submit" name="intent" value="draft" variant="outline">{{ __('Save draft') }}</x-ui.button>
                            <x-ui.button type="submit" name="intent" value="submit">{{ __('Submit for review') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <aside class="flex flex-col gap-4">
            <x-ui.card :title="__('Listing checklist')" :subtitle="__('Gates are set by Nawy per country and category.')">
                <ul class="flex flex-col gap-2 text-sm" data-gates>
                    @foreach ($checklist as $gate => $check)
                        <li class="flex items-center justify-between gap-2" data-gate="{{ $gate }}">
                            <span>{{ $check['label'] }}</span>
                            @if (! $check['required'])
                                <x-ui.badge tone="neutral">{{ __('Not required') }}</x-ui.badge>
                            @elseif ($check['passed'])
                                <x-ui.badge tone="success">{{ __('Done') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger">{{ __('Missing') }}</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </aside>
    </div>
</x-layouts.panel>
