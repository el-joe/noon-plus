<x-layouts.panel :title="__('Coupons')" :subtitle="__('Nawy and seller coupons in :country · times in :country time, Oman shown on edit', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Coupons')]">
    <x-slot:actions>
        @can('coupons.manage')
            <x-ui.button :href="route('admin.coupons.create')">{{ __('New coupon') }}</x-ui.button>
        @endcan
    </x-slot:actions>

    <form method="GET" action="{{ route('admin.coupons') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-5" role="search">
        <x-ui.input :label="__('Code')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('Issuer')" name="scope" :selected="$filters['scope'] ?? ''" :placeholder="__('All')" :options="['platform' => __('Nawy'), 'vendor' => __('Seller')]" />
        <x-ui.select :label="__('Type')" name="type" :selected="$filters['type'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Coupons\Enums\CouponType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" />
        <x-ui.select :label="__('State')" name="state" :selected="$filters['state'] ?? ''" :placeholder="__('All')" :options="['live' => __('Live'), 'scheduled' => __('Scheduled'), 'ended' => __('Ended'), 'inactive' => __('Switched off')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Code'), __('Offer'), __('Applies to'), __('Funded by'), __('Used'), __('Ends (Oman)'), __('Status')]" :rows="$coupons->isNotEmpty()" :empty="__('No coupons yet.')">
            @foreach ($coupons as $coupon)
                <tr class="border-t border-line" data-coupon="{{ $coupon->code }}">
                    <td class="px-5 py-3 font-mono font-medium"><a class="text-fg-link" href="{{ route('admin.coupons.show', $coupon->id) }}">{{ $coupon->code }}</a><span class="block font-sans text-xs text-fg-secondary">{{ $coupon->scope === \App\Domains\Coupons\Enums\CouponScope::Vendor ? __('Seller coupon') : __('Nawy coupon') }}</span></td>
                    <td class="px-5 py-3">@include('panels.admin.coupons._summary')</td>
                    <td class="px-5 py-3">{{ $coupon->target_type->label() }}@if ($coupon->first_order_only)<span class="block text-xs text-fg-secondary">{{ __('First order only') }}</span>@endif</td>
                    <td class="px-5 py-3">{{ $coupon->funding() === \App\Domains\Coupons\Enums\CouponFunding::Vendor ? __('Seller') : __('Nawy') }}</td>
                    <td class="px-5 py-3">{{ $coupon->redemptions_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $coupon->ends_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="! $coupon->is_active ? 'inactive' : ($coupon->ends_at?->isPast() ? 'closed' : ($coupon->starts_at?->isFuture() ? 'scheduled' : 'live'))" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $coupons->links() }}
</x-layouts.panel>
