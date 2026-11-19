@php($editing = $coupon->exists)
@php($oldList = fn (string $name, string $target) => old($name, $coupon->target_type?->value === $target ? $coupon->targetIds() : []))
<x-layouts.panel :title="$editing ? __('Edit :code', ['code' => $coupon->code]) : __('New coupon')" :subtitle="__('Coupon in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Coupons'), $editing ? __('Edit') : __('New')]">
    <form method="POST" action="{{ $editing ? route('admin.coupons.update', $coupon->id) : route('admin.coupons.store') }}" class="flex flex-col gap-6" data-coupon-form>
        @csrf
        @if ($editing) @method('PUT') @endif
        <x-ui.card :title="__('Code and issuer')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input :label="__('Code')" name="code" :value="$coupon->code" required :helper="__('Letters, numbers, - and _; stored in capitals.')" />
                <x-ui.input :label="__('Internal description')" name="description" :value="$coupon->description" class="sm:col-span-2" />
                <x-ui.select :label="__('Issuer (one of each per order)')" name="scope" :selected="$coupon->scope?->value" :options="['platform' => __('Nawy coupon'), 'vendor' => __('Seller coupon')]" />
                <x-ui.select :label="__('Seller (seller coupons)')" name="vendor_id" :selected="$coupon->vendor_id" :placeholder="__('—')" :options="$vendors" />
                <x-ui.select :label="__('Funded by')" name="funded_by" :selected="$coupon->funded_by?->value ?? 'platform'" :options="['platform' => __('Nawy (promo expense)'), 'vendor' => __('Seller (deducted from the settlement)')]" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Discount')">
            <div class="grid gap-4 sm:grid-cols-4">
                <x-ui.select :label="__('Type')" name="type" :selected="$coupon->type?->value" :options="collect(\App\Domains\Coupons\Enums\CouponType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" />
                <x-ui.input :label="__('Value (% or amount; BOGO: % off the free items, 100 = free)')" name="value" :value="$coupon->exists ? $coupon->value : ''" inputmode="decimal" />
                <x-ui.input :label="__('BOGO: buy')" name="buy_quantity" type="number" :value="$coupon->buy_quantity" />
                <x-ui.input :label="__('BOGO: get')" name="get_quantity" type="number" :value="$coupon->get_quantity" />
                <x-ui.input :label="__('Minimum order (:currency)', ['currency' => $country->currency])" name="min_order_amount" :value="$coupon->min_order_amount" inputmode="decimal" />
                <x-ui.input :label="__('Maximum discount (:currency)', ['currency' => $country->currency])" name="max_discount_amount" :value="$coupon->max_discount_amount" inputmode="decimal" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Applies to')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select :label="__('Items')" name="target_type" :selected="$coupon->target_type?->value ?? 'all'" :options="collect(\App\Domains\Coupons\Enums\CouponTarget::cases())->mapWithKeys(fn ($target) => [$target->value => $target->label()])->all()" />
                <div></div>
                <div class="flex flex-col gap-1.5">
                    <label for="target_category_ids" class="text-sm font-medium">{{ __('Categories (sub-categories included)') }}</label>
                    <select id="target_category_ids" name="target_category_ids[]" multiple size="6" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                        @foreach ($categories as $id => $name)<option value="{{ $id }}" @selected(in_array($id, (array) $oldList('target_category_ids', 'category')))>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="target_brand_ids" class="text-sm font-medium">{{ __('Brands') }}</label>
                    <select id="target_brand_ids" name="target_brand_ids[]" multiple size="6" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                        @foreach ($brands as $id => $name)<option value="{{ $id }}" @selected(in_array($id, (array) $oldList('target_brand_ids', 'brand')))>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <x-ui.input :label="__('Product ids (comma separated)')" name="target_product_ids" :value="$coupon->target_type?->value === 'product' ? implode(', ', $coupon->targetIds()) : ''" />
                <x-ui.input :label="__('Seller ids (comma separated)')" name="target_vendor_ids" :value="$coupon->target_type?->value === 'vendor' ? implode(', ', $coupon->targetIds()) : ''" />
                @error('target_type')<p class="text-xs text-fg-danger sm:col-span-2">{{ $message }}</p>@enderror
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Customers, limits and dates')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select :label="__('Customer segment')" name="customer_segment" :selected="$coupon->customer_segment?->value ?? 'everyone'" :options="collect(\App\Domains\Coupons\Enums\CustomerSegment::cases())->mapWithKeys(fn ($segment) => [$segment->value => $segment->label()])->all()" />
                <x-ui.input :label="__('Total uses')" name="usage_limit" type="number" :value="$coupon->usage_limit" :helper="__('Empty = unlimited.')" />
                <x-ui.input :label="__('Uses per customer')" name="per_customer_limit" type="number" :value="$coupon->per_customer_limit" />
                <x-ui.datetime-input :label="__('Starts')" name="starts_at" :country="$country" :value="$coupon->starts_at" />
                <x-ui.datetime-input :label="__('Ends')" name="ends_at" :country="$country" :value="$coupon->ends_at" />
                <div class="flex flex-col justify-end gap-2">
                    <x-ui.checkbox :label="__('First order only')" name="first_order_only" :checked="$coupon->first_order_only" />
                    <x-ui.checkbox :label="__('Active')" name="is_active" :checked="$coupon->is_active ?? true" />
                    <x-ui.checkbox :label="__('Show in “Offers for you” and on product pages')" name="is_public" :checked="$coupon->is_public ?? false" />
                </div>
            </div>
        </x-ui.card>
        <div class="flex gap-2"><x-ui.button type="submit">{{ $editing ? __('Save coupon') : __('Create coupon') }}</x-ui.button><x-ui.button variant="ghost" :href="route('admin.coupons')">{{ __('Cancel') }}</x-ui.button></div>
    </form>
</x-layouts.panel>
