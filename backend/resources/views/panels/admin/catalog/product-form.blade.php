<x-layouts.panel :title="__('Edit :name', ['name' => $product->title_en])" :subtitle="__('Product in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Catalog'), __('Products'), __('Edit')]">
    @if ($liveListings > 0)
        <div role="note" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ trans_choice('This product has :count live offer. Making it inactive hides it from the storefront and blocks new offer approvals.|This product has :count live offers. Making it inactive hides it from the storefront and blocks new offer approvals.', $liveListings) }}</div>
    @endif
    <form method="POST" action="{{ route('admin.catalog.products.update', $product->id) }}" class="flex flex-col gap-6">
        @csrf @method('PUT')
        <x-ui.card :title="__('Copy')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input :label="__('Title (English)')" name="title_en" :value="$product->title_en" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" :value="$product->title_ar" dir="rtl" required />
                <x-ui.input :label="__('Slug')" name="slug" :value="$product->slug" required />
                <div></div>
                <x-ui.textarea :label="__('Description (English)')" name="description_en" :value="$product->description_en" :rows="6" />
                <x-ui.textarea :label="__('Description (Arabic)')" name="description_ar" :value="$product->description_ar" :rows="6" dir="rtl" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Classification and status')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select :label="__('Category')" name="category_id" :selected="$product->category_id" :options="$categories" />
                <x-ui.select :label="__('Brand')" name="brand_id" :selected="$product->brand_id" :placeholder="__('No brand')" :options="$brands" />
                <x-ui.select :label="__('Size guide')" name="size_guide_template_id" :selected="$product->size_guide_template_id" :placeholder="__('Category default')" :options="$sizeGuides" />
                <x-ui.select :label="__('Status')" name="status" :selected="$product->status" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" />
                <x-ui.select :label="__('Fulfilment')" name="fulfilment_mode" :selected="$product->fulfilment_mode" :options="['ready_to_ship' => __('Ready to ship'), 'made_to_order' => __('Made to order')]" />
                <x-ui.input :label="__('Lead days (made to order)')" name="lead_days" type="number" :value="$product->lead_days" />
            </div>
        </x-ui.card>
        <div class="flex gap-2"><x-ui.button type="submit">{{ __('Save product') }}</x-ui.button><x-ui.button variant="ghost" :href="route('admin.catalog.products.show', $product->id)">{{ __('Cancel') }}</x-ui.button></div>
    </form>
</x-layouts.panel>
