@php($editing = $coupon->exists)
@php($oldList = fn (string $name, string $target) => array_map('intval', (array) old($name, $coupon->target_type?->value === $target ? $coupon->targetIds() : [])))
<x-layouts.panel :title="$editing ? __('Edit :code', ['code' => $coupon->code]) : __('New coupon')" :subtitle="__('Paid by your store: the discount is taken off your statement')" :breadcrumbs="[__('Growth'), __('Coupons'), $editing ? __('Edit') : __('New')]">
    <form method="POST" action="{{ $editing ? route('partner.coupons.update', $coupon->id) : route('partner.coupons.store') }}" class="flex flex-col gap-6" data-seller-coupon-form>
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($locked)
            <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Customers have used this coupon, so its code and discount cannot change. You can still change its limits, dates and visibility.') }}</div>
        @endif
        <x-ui.card :title="__('Code and discount')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input :label="__('Code')" name="code" :value="$coupon->code" required :readonly="$locked" :helper="__('Letters, numbers, - and _; stored in capitals.')" />
                <x-ui.input :label="__('Internal note')" name="description" :value="$coupon->description" class="sm:col-span-2" />
                <x-ui.select :label="__('Type')" name="type" :selected="$coupon->type?->value" :options="collect(\App\Domains\Coupons\Support\SellerCoupons::TYPES)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" />
                <x-ui.input :label="__('Value (% or :currency; BOGO: % off the free items, 100 = free)', ['currency' => $country->currency])" name="value" :value="$editing ? $coupon->value : ''" inputmode="decimal" :helper="__('Percent off is at most :max%.', ['max' => $maxPercent])" />
                <div class="grid grid-cols-2 gap-4">
                    <x-ui.input :label="__('BOGO: buy')" name="buy_quantity" type="number" :value="$coupon->buy_quantity" />
                    <x-ui.input :label="__('BOGO: get')" name="get_quantity" type="number" :value="$coupon->get_quantity" />
                </div>
                <x-ui.input :label="__('Minimum order (:currency)', ['currency' => $country->currency])" name="min_order_amount" :value="$coupon->min_order_amount" inputmode="decimal" :helper="__('Counted on your items only.')" />
                <x-ui.input :label="__('Maximum discount (:currency)', ['currency' => $country->currency])" name="max_discount_amount" :value="$coupon->max_discount_amount" inputmode="decimal" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Applies to')" :subtitle="__('Only your own items are ever discounted.')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select :label="__('Items')" name="target_type" :selected="$coupon->target_type?->value ?? 'all'" :options="['all' => __('All my products'), 'product' => __('Some of my products'), 'category' => __('My products in some categories'), 'brand' => __('My products of some brands')]" class="sm:col-span-3" />
                @foreach (['product' => [__('Products'), $products], 'category' => [__('Categories (sub-categories included)'), $categories], 'brand' => [__('Brands'), $brands]] as $target => [$label, $options])
                    <div class="flex flex-col gap-1.5">
                        <label for="target_{{ $target }}_ids" class="text-sm font-medium">{{ $label }}</label>
                        <select id="target_{{ $target }}_ids" name="target_{{ $target }}_ids[]" multiple size="8" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                            @foreach ($options as $id => $name)<option value="{{ $id }}" @selected(in_array((int) $id, $oldList('target_'.$target.'_ids', $target), true))>{{ $name }}</option>@endforeach
                        </select>
                        @error('target_'.$target.'_ids')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                        @if ($options === [])<p class="text-xs text-fg-secondary">{{ __('Nothing yet: add products to your store first.') }}</p>@endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Customers, limits and dates')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select :label="__('Customer segment')" name="customer_segment" :selected="$coupon->customer_segment?->value ?? 'everyone'" :options="collect(\App\Domains\Coupons\Enums\CustomerSegment::cases())->mapWithKeys(fn ($segment) => [$segment->value => $segment->label()])->all()" />
                <x-ui.input :label="__('Total uses')" name="usage_limit" type="number" :value="$coupon->usage_limit" :helper="__('Empty = unlimited.')" />
                <x-ui.input :label="__('Uses per customer')" name="per_customer_limit" type="number" :value="$coupon->per_customer_limit" />
                <x-ui.datetime-input :label="__('Starts')" name="starts_at" :country="$country" :value="$coupon->starts_at" />
                <div class="flex flex-col gap-1">
                    <x-ui.datetime-input :label="__('Ends')" name="ends_at" :country="$country" :value="$coupon->ends_at" required />
                    @error('ends_at')<p class="text-xs text-fg-danger">{{ $message }}</p>@else<p class="text-xs text-fg-secondary">{{ __('At most :days days.', ['days' => $maxDays]) }}</p>@enderror
                </div>
                <div class="flex flex-col justify-end gap-2">
                    <x-ui.checkbox :label="__('First order only')" name="first_order_only" :checked="$coupon->first_order_only" />
                    <x-ui.checkbox :label="__('Switched on')" name="is_active" :checked="$coupon->is_active ?? true" />
                    <x-ui.checkbox :label="__('Show in “Offers for you” and on product pages')" name="is_public" :checked="$coupon->is_public ?? false" />
                    @error('is_active')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>
        <div class="flex gap-2"><x-ui.button type="submit">{{ $editing ? __('Save coupon') : __('Create coupon') }}</x-ui.button><x-ui.button variant="ghost" :href="$editing ? route('partner.coupons.show', $coupon->id) : route('partner.coupons')">{{ __('Cancel') }}</x-ui.button></div>
    </form>
</x-layouts.panel>
