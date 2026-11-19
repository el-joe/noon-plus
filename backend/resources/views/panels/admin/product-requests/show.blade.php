<x-layouts.panel :title="$productRequest->number" :subtitle="$productRequest->title_en" :breadcrumbs="[__('Commerce'), __('Product requests'), $productRequest->number]">
    <x-slot:actions><x-ui.status-badge :status="$productRequest->status" /></x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Request')" class="xl:col-span-2">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('From') }}</dt><dd>{{ $productRequest->vendor?->store_name ?? $marketer?->display_name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Brand') }}</dt><dd>{{ $productRequest->brand_name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Product name (Arabic)') }}</dt><dd dir="rtl">{{ $productRequest->title_ar ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Barcode (GTIN)') }}</dt><dd>{{ $productRequest->gtin ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Model number') }}</dt><dd>{{ $productRequest->model_number ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Category') }}</dt><dd>{{ $productRequest->category?->name_en ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Description') }}</dt><dd>{{ $productRequest->description ?? '—' }}</dd></div>
                @if ($productRequest->reference_url)
                    <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Reference link') }}</dt><dd><a class="text-fg-link" href="{{ $productRequest->reference_url }}" rel="noopener noreferrer" target="_blank">{{ $productRequest->reference_url }}</a></dd></div>
                @endif
                @if ($productRequest->product)
                    <div><dt class="text-fg-secondary">{{ __('Product') }}</dt><dd><a class="text-fg-link" href="{{ route('admin.catalog.products.show', $productRequest->product_id) }}">{{ $productRequest->product->title_en }}</a></dd></div>
                @endif
                @if ($productRequest->rejection_reason)
                    <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Rejection reason') }}</dt><dd>{{ $productRequest->rejection_reason }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        @can('product_requests.review')
            @if ($productRequest->status === \App\Domains\Catalog\Enums\ProductRequestStatus::Pending)
                <div class="flex flex-col gap-6">
                    <x-ui.card :title="__('Approve and create the product')">
                        <form method="POST" action="{{ route('admin.product-requests.approve', $productRequest->id) }}" class="flex flex-col gap-3" data-approve-form>
                            @csrf
                            <x-ui.select :label="__('Category')" name="category_id" :options="$categories" :selected="$productRequest->category_id" required />
                            <x-ui.select :label="__('Brand')" name="brand_id" :options="$brands" :placeholder="__('No brand')" />
                            <x-ui.input :label="__('Product name (English)')" name="title_en" :value="$productRequest->title_en" required />
                            <x-ui.input :label="__('Product name (Arabic)')" name="title_ar" :value="$productRequest->title_ar" required />
                            <x-ui.input :label="__('Barcode (GTIN)')" name="gtin" :value="$productRequest->gtin" />
                            <x-ui.textarea :label="__('Description')" name="description_en" :value="$productRequest->description" :rows="3" />
                            <x-ui.checkbox :label="__('Publish the product now (otherwise it stays a draft)')" name="activate" />
                            <div><x-ui.button type="submit">{{ __('Approve') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                    <x-ui.card :title="__('Reject')">
                        <form method="POST" action="{{ route('admin.product-requests.reject', $productRequest->id) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.textarea :label="__('Reason')" name="reason" :rows="2" required />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Reject') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                </div>
            @endif
        @endcan
    </div>

    @include('panels.admin._partials.transitions', ['transitions' => $productRequest->transitions])
</x-layouts.panel>
