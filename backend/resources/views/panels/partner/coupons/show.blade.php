@php($clock = app(\App\Domains\Countries\Support\CountryClock::class))
@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
@php($local = fn ($moment) => $clock->inCountry($country, $moment)->format('j M Y, H:i'))
<x-layouts.panel :title="$coupon->code" :subtitle="$coupon->description ?: __('Seller coupon · times in :zone', ['zone' => $country->timezone])" :breadcrumbs="[__('Growth'), __('Coupons'), $coupon->code]">
    <x-slot:actions>
        <form method="POST" action="{{ route('partner.coupons.toggle', $coupon->id) }}">
            @csrf
            <x-ui.button type="submit" variant="outline">{{ $coupon->is_active ? __('Switch off') : __('Switch on') }}</x-ui.button>
        </form>
        <x-ui.button :href="route('partner.coupons.edit', $coupon->id)">{{ __('Edit coupon') }}</x-ui.button>
    </x-slot:actions>

    @error('is_active')
        <div role="alert" class="rounded-lg border border-line bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>
    @enderror
    @if ($coupon->admin_disabled_at)
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Nawy switched this coupon off on :date. Contact support to switch it on again.', ['date' => $local($coupon->admin_disabled_at)]) }}</div>
    @endif

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Offer')" :value="trim(view('panels.admin.coupons._summary', ['coupon' => $coupon, 'country' => $country])->render())" />
        <x-ui.stat-card :label="__('Times used')" :value="$coupon->redemptions_count.($coupon->usage_limit ? ' / '.$coupon->usage_limit : '')" />
        <x-ui.stat-card :label="__('Customers')" :value="$customers" />
        <x-ui.stat-card :label="__('Discount you paid')" :value="$fmt->format((string) $given->getAmount(), $country->currency)" data-coupon-given />
    </section>

    <x-ui.card :title="__('Rules')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Applies to') }}</dt><dd>{{ $coupon->target_type === \App\Domains\Coupons\Enums\CouponTarget::All ? __('All my products') : $coupon->target_type->label().': '.implode(', ', $targets) }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Customers') }}</dt><dd>{{ $coupon->customer_segment->label() }}{{ $coupon->first_order_only ? ' · '.__('first order only') : '' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Shown to shoppers') }}</dt><dd>{{ $coupon->is_public ? __('Yes') : __('No, code only') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Minimum order') }}</dt><dd>{{ $coupon->min_order_amount !== null ? $fmt->format((string) $coupon->min_order_amount, $country->currency) : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Maximum discount') }}</dt><dd>{{ $coupon->max_discount_amount !== null ? $fmt->format((string) $coupon->max_discount_amount, $country->currency) : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Per customer') }}</dt><dd>{{ $coupon->per_customer_limit ?? __('Unlimited') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Starts') }}</dt><dd>{{ $coupon->starts_at ? $local($coupon->starts_at) : __('Now') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Ends') }}</dt><dd>{{ $coupon->ends_at ? $local($coupon->ends_at) : __('No end') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd>{{ $coupon->isLive() ? __('Live') : ($coupon->is_active ? ($coupon->ends_at?->isPast() ? __('Ended') : __('Scheduled')) : __('Switched off')) }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Redemptions')" flush>
        <x-ui.table :headers="[__('Order'), __('Order discount'), __('Paid by you'), __('When')]" :rows="$redemptions->isNotEmpty()" :empty="__('Not used yet.')">
            @foreach ($redemptions as $redemption)
                <tr class="border-t border-line" data-redemption="{{ $redemption->id }}">
                    <td class="px-5 py-3 font-medium">{{ $orders[$redemption->order_id] ?? '#'.$redemption->order_id }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $redemption->discount_amount" :currency="$country->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$shareOf($redemption)" :currency="$country->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $local($redemption->created_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $redemptions->links() }}
</x-layouts.panel>
