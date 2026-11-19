@php($clock = app(\App\Domains\Countries\Support\CountryClock::class))
<x-layouts.panel :title="__('Coupons')" :subtitle="__('Your own coupons, paid by your store and taken off your statement · times in :zone', ['zone' => $country->timezone])" :breadcrumbs="[__('Growth'), __('Coupons')]">
    <x-slot:actions>
        @if (app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'partner', 'marketing', true))
            <x-ui.button :href="route('partner.coupons.create')">{{ __('New coupon') }}</x-ui.button>
        @endif
    </x-slot:actions>

    @error('is_active')
        <div role="alert" class="rounded-lg border border-line bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>
    @enderror

    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Switched on')" :value="$liveCount.' / '.$maxLive" />
        <x-ui.stat-card :label="__('Coupons')" :value="$coupons->total()" />
        <x-ui.stat-card :label="__('Times used')" :value="$coupons->sum('redemptions_count')" />
    </section>

    <form method="GET" action="{{ route('partner.coupons') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Code')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('State')" name="state" :selected="$filters['state'] ?? ''" :placeholder="__('All')" :options="['live' => __('Live'), 'scheduled' => __('Scheduled'), 'ended' => __('Ended'), 'off' => __('Switched off')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Code'), __('Offer'), __('Applies to'), __('Used'), __('Discount given'), __('Ends'), __('Status'), '']" :rows="$coupons->isNotEmpty()" :empty="__('No coupons yet. Create one to reward your customers.')">
            @foreach ($coupons as $coupon)
                <tr class="border-t border-line" data-coupon="{{ $coupon->code }}">
                    <td class="px-5 py-3 font-mono font-medium"><a class="text-fg-link" href="{{ route('partner.coupons.show', $coupon->id) }}">{{ $coupon->code }}</a>@if ($coupon->is_public)<span class="block font-sans text-xs text-fg-secondary">{{ __('Shown to shoppers') }}</span>@endif</td>
                    <td class="px-5 py-3">@include('panels.admin.coupons._summary')</td>
                    <td class="px-5 py-3">{{ $coupon->target_type === \App\Domains\Coupons\Enums\CouponTarget::All ? __('All my products') : $coupon->target_type->label() }}</td>
                    <td class="px-5 py-3">{{ $coupon->redemptions_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) ($coupon->redemptions_sum_discount_amount ?? '0')" :currency="$country->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $coupon->ends_at ? $clock->inCountry($country, $coupon->ends_at)->format('j M Y, H:i') : '—' }}</td>
                    <td class="px-5 py-3">
                        @if ($coupon->admin_disabled_at)
                            <x-ui.badge tone="danger">{{ __('Switched off by Nawy') }}</x-ui.badge>
                        @elseif (! $coupon->is_active)
                            <x-ui.badge>{{ __('Switched off') }}</x-ui.badge>
                        @elseif ($coupon->ends_at?->isPast())
                            <x-ui.badge>{{ __('Ended') }}</x-ui.badge>
                        @elseif ($coupon->starts_at?->isFuture())
                            <x-ui.badge tone="info">{{ __('Scheduled') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="success">{{ __('Live') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-end">
                        <form method="POST" action="{{ route('partner.coupons.toggle', $coupon->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="ghost">{{ $coupon->is_active ? __('Switch off') : __('Switch on') }}</x-ui.button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $coupons->links() }}
</x-layouts.panel>
