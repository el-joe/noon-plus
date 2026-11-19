<x-layouts.panel :title="__('Product requests')" :subtitle="__('Ask Nawy to add a product that is not in the catalogue yet')" :breadcrumbs="[__('Products'), __('Product requests')]">
    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Your requests')" flush class="xl:col-span-2">
            <x-ui.table :headers="[__('Request'), __('Product'), __('Barcode (GTIN)'), __('Sent'), __('Status'), '']" :rows="$requests->isNotEmpty()" :empty="__('No product requests yet.')">
                @foreach ($requests as $row)
                    <tr class="border-t border-line align-top" data-product-request="{{ $row->number }}">
                        <td class="px-5 py-3 font-medium">{{ $row->number }}</td>
                        <td class="px-5 py-3">{{ $row->title_en }}@if ($row->brand_name)<span class="block text-xs text-fg-secondary">{{ $row->brand_name }}</span>@endif
                            @if ($row->rejection_reason)<span class="block text-xs text-fg-danger">{{ $row->rejection_reason }}</span>@endif
                        </td>
                        <td class="px-5 py-3 text-xs">{{ $row->gtin ?? '—' }}</td>
                        <td class="px-5 py-3 text-xs">{{ $row->created_at?->format('j M Y') }}</td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$row->status" /></td>
                        <td class="px-5 py-3 text-end">
                            @if ($row->status === \App\Domains\Catalog\Enums\ProductRequestStatus::Pending)
                                <form method="POST" action="{{ route($panel.'.product-requests.cancel', $row->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                                </form>
                            @elseif ($panel === 'partner' && $row->product_id)
                                <x-ui.button size="sm" variant="outline" :href="route('partner.products.create', ['q' => $row->title_en])">{{ __('Sell this') }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Request a product')" :subtitle="__('We check the catalogue for the same barcode or name first.')">
            <form method="POST" action="{{ route($panel.'.product-requests.store') }}" class="flex flex-col gap-3" data-product-request-form>
                @csrf
                <x-ui.input :label="__('Product name (English)')" name="title_en" required />
                <x-ui.input :label="__('Product name (Arabic)')" name="title_ar" />
                <x-ui.input :label="__('Brand')" name="brand_name" />
                <x-ui.select :label="__('Category')" name="category_id" :placeholder="__('Not sure')"
                             :options="$categories->mapWithKeys(fn ($category) => [$category->id => $category->name()])->all()" />
                <x-ui.input :label="__('Barcode (GTIN)')" name="gtin" inputmode="numeric" :helper="__('8 to 14 digits, from the pack')" />
                <x-ui.input :label="__('Model number')" name="model_number" />
                <x-ui.input :label="__('Reference link')" name="reference_url" type="url" />
                <x-ui.textarea :label="__('Description')" name="description" :rows="3" />
                <div><x-ui.button type="submit">{{ __('Send request') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
    {{ $requests->links() }}
</x-layouts.panel>
