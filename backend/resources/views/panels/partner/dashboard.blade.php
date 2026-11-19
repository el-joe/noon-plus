<x-layouts.panel :title="$greeting.', '.$vendor->store_name"
                 :subtitle="__(':country store · today :date (:zone time)', ['country' => $country->name_en, 'date' => $localNow->format('j M Y'), 'zone' => $country->timezone])"
                 :breadcrumbs="[__('Seller Center'), __('Dashboard')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('partner.dashboard.report')">{{ __('Download report') }}</x-ui.button>
        @if (app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'partner', 'listings', true))
            <x-ui.button :href="route('partner.products.create')">{{ __('Add product') }}</x-ui.button>
        @endif
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Key figures') }}">
        <x-ui.stat-card :label="__('Sales today')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($salesToday, $country->currency)" data-kpi="sales-today" />
        <x-ui.stat-card :label="__('Orders to confirm')" :value="$ordersToConfirm" data-kpi="orders-to-confirm" />
        <x-ui.stat-card :label="__('Returns open')" :value="$returnsOpen" data-kpi="returns-open" />
        <x-ui.stat-card :label="__('Account health')" :value="$score.' / 100'" data-kpi="health" />
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Sales — last 14 days')" class="xl:col-span-2">
            @php($peak = collect($salesByDay)->reduce(fn ($max, $value) => bccomp($value, $max, 4) > 0 ? $value : $max, '0'))
            <div class="flex h-40 items-end gap-2" role="img" aria-label="{{ __('Daily sales for the last 14 days') }}">
                @foreach ($salesByDay as $day => $amount)
                    @php($height = bccomp($peak, '0', 4) > 0 ? max(2, (int) bcdiv(bcmul($amount, '100', 4), $peak, 0)) : 2)
                    <div class="flex grow flex-col items-center gap-1" title="{{ $day }}: {{ $country->currency }} {{ $amount }}">
                        <div class="w-full rounded-t bg-brand" style="height: {{ $height }}%"></div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Action needed')">
            <ul class="flex flex-col gap-2 text-sm">
                @if ($ordersToConfirm > 0)
                    <li class="flex justify-between gap-2"><a class="text-fg-link" href="{{ route('partner.orders', ['status' => 'placed']) }}">{{ trans_choice(':count order to confirm|:count orders to confirm', $ordersToConfirm) }}</a><x-ui.badge tone="danger">{{ __('Urgent') }}</x-ui.badge></li>
                @endif
                @unless ($vendor->hasAcceptedSellerAgreement())
                    <li class="flex justify-between gap-2"><span>{{ __('Accept the Seller Agreement to list products') }}</span><x-ui.badge tone="warning">{{ __('Contract') }}</x-ui.badge></li>
                @endunless
                @if ($rejectedListings > 0)
                    <li class="flex justify-between gap-2"><a class="text-fg-link" href="{{ route('partner.products', ['status' => 'rejected']) }}">{{ trans_choice(':count listing rejected|:count listings rejected', $rejectedListings) }}</a><x-ui.badge tone="danger">{{ __('Fix') }}</x-ui.badge></li>
                @endif
                @if ($outOfStockListings > 0)
                    <li class="flex justify-between gap-2"><a class="text-fg-link" href="{{ route('partner.products', ['stock' => 'out']) }}">{{ trans_choice(':count listing out of stock|:count listings out of stock', $outOfStockListings) }}</a><x-ui.badge tone="warning">{{ __('Restock') }}</x-ui.badge></li>
                @endif
                @if ($ordersToConfirm === 0 && $rejectedListings === 0 && $outOfStockListings === 0 && $vendor->hasAcceptedSellerAgreement())
                    <li class="text-fg-secondary">{{ __('All caught up.') }}</li>
                @endif
            </ul>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Recent orders')" flush>
        <x-ui.table :headers="[__('Order'), __('Items'), __('Total'), __('Status')]" :rows="$recentOrders->isNotEmpty()" :empty="__('No orders yet.')">
            @foreach ($recentOrders as $subOrder)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('partner.orders.show', $subOrder->id) }}">{{ $subOrder->number }}</a></td>
                    <td class="px-5 py-3">{{ $subOrder->items_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$subOrder->total" :currency="$country->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subOrder->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
