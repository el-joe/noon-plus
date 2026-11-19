@php($oman = fn ($moment) => $moment?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i').' '.__('Oman'))
<x-layouts.panel :title="$coupon->code" :subtitle="$coupon->description ?: __('Coupon in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Coupons'), $coupon->code]">
    <x-slot:actions>
        @can('coupons.manage')
            <form method="POST" action="{{ route('admin.coupons.toggle', $coupon->id) }}" data-coupon-toggle>
                @csrf
                <x-ui.button type="submit" :variant="$coupon->is_active ? 'danger' : 'outline'">{{ $coupon->is_active ? __('Switch off') : __('Switch on') }}</x-ui.button>
            </form>
            <x-ui.button :href="route('admin.coupons.edit', $coupon->id)">{{ __('Edit coupon') }}</x-ui.button>
        @endcan
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Offer')" :value="trim(view('panels.admin.coupons._summary', ['coupon' => $coupon, 'country' => $country])->render())" />
        <x-ui.stat-card :label="__('Redemptions')" :value="$coupon->redemptions_count.($coupon->usage_limit ? ' / '.$coupon->usage_limit : '')" />
        <x-ui.stat-card :label="__('Discount given')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) ($coupon->redemptions_sum_discount_amount ?? '0'), $country->currency)" />
    </section>

    <x-ui.card :title="__('Rules')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Issuer') }}</dt><dd>{{ $vendor ? __('Seller: :name', ['name' => $vendor->store_name]) : __('Nawy') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Funded by') }}</dt><dd>{{ $coupon->funding() === \App\Domains\Coupons\Enums\CouponFunding::Vendor ? __('Seller (taken from the settlement)') : __('Nawy (promo expense)') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Applies to') }}</dt><dd>{{ $coupon->target_type->label() }}{{ $targets ? ': '.implode(', ', $targets) : '' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Customers') }}</dt><dd>{{ $coupon->customer_segment->label() }}{{ $coupon->first_order_only ? ' · '.__('first order only') : '' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Minimum order') }}</dt><dd>{{ $coupon->min_order_amount !== null ? app(\App\Shared\Money\MoneyFormatter::class)->format((string) $coupon->min_order_amount, $country->currency) : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Maximum discount') }}</dt><dd>{{ $coupon->max_discount_amount !== null ? app(\App\Shared\Money\MoneyFormatter::class)->format((string) $coupon->max_discount_amount, $country->currency) : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Per customer') }}</dt><dd>{{ $coupon->per_customer_limit ?? __('Unlimited') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Starts') }}</dt><dd>{{ $coupon->starts_at ? $oman($coupon->starts_at) : __('Now') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Ends') }}</dt><dd>{{ $coupon->ends_at ? $oman($coupon->ends_at) : __('No end') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd>{{ $coupon->is_active ? __('Switched on') : ($coupon->admin_disabled_at ? __('Switched off by Nawy on :date', ['date' => $oman($coupon->admin_disabled_at)]) : __('Switched off')) }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Latest redemptions')" flush>
        <x-ui.table :headers="[__('Order'), __('Customer'), __('Discount'), __('Funded by'), __('When (Oman)')]" :rows="$redemptions->isNotEmpty()" :empty="__('Not used yet.')">
            @foreach ($redemptions as $redemption)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">@can('orders.view')<a class="text-fg-link" href="{{ route('admin.orders.show', $redemption->order_id) }}">#{{ $redemption->order_id }}</a>@else #{{ $redemption->order_id }} @endcan</td>
                    <td class="px-5 py-3">{{ app(\App\Shared\Support\UserNames::class)->name($redemption->customer_user_id) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $redemption->discount_amount" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">{{ ucfirst($redemption->funded_by ?? $coupon->funding()->value) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $oman($redemption->created_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
