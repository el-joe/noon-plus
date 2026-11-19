<x-layouts.panel :title="__('Inventory & warehouses')" :subtitle="__('Fulfilled by Nawy: units in Nawy warehouses, ready to ship fast')" :breadcrumbs="[__('Main'), __('Inventory & warehouses')]">
    <x-slot:actions>
        @if (app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'partner', 'inventory', true))
            <x-ui.button :href="route('partner.inventory.inbound.create')">{{ __('New inbound plan') }}</x-ui.button>
        @endif
    </x-slot:actions>

    @include('panels.partner.inventory._nav')

    @unless ($hasWarehouses)
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('No FBN warehouse is open in this country yet. You can enrol products now; inbound plans open with the first warehouse.') }}</div>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card :label="__('Available to sell')" :value="number_format($totals['available'])" />
        <x-ui.stat-card :label="__('Reserved for orders')" :value="number_format($totals['reserved'])" />
        <x-ui.stat-card :label="__('Damaged (unsellable)')" :value="number_format($totals['damaged'])" />
        <x-ui.stat-card :label="__('Low stock SKUs')" :value="number_format($totals['low'])" />
        <x-ui.stat-card :label="__('Open inbound plans')" :value="number_format($totals['open_inbound'])" />
    </div>

    @if (array_sum($alerts) > 0)
        <x-ui.card :title="__('Alerts')">
            <ul class="flex flex-col gap-2 px-5 pb-4 text-sm" data-fbn-alerts>
                @if ($alerts['low'] > 0)
                    <li><a class="text-fg-brand underline" href="{{ route('partner.inventory', ['alert' => 'low']) }}">{{ __(':count SKUs at or below :threshold units — send more stock.', ['count' => $alerts['low'], 'threshold' => $threshold]) }}</a></li>
                @endif
                @if ($alerts['long_term'] > 0)
                    <li><a class="text-fg-brand underline" href="{{ route('partner.inventory', ['alert' => 'long_term']) }}">{{ __(':count SKUs in long-term storage — fees apply.', ['count' => $alerts['long_term']]) }}</a></li>
                @endif
                @if ($alerts['unsellable'] > 0)
                    <li><a class="text-fg-brand underline" href="{{ route('partner.inventory', ['alert' => 'unsellable']) }}">{{ __(':count SKUs with unsellable units — request a removal.', ['count' => $alerts['unsellable']]) }}</a></li>
                @endif
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('FBN stock per SKU and warehouse')" flush>
        @if ($filter)
            <p class="px-5 pb-3 text-sm"><a class="text-fg-brand underline" href="{{ route('partner.inventory') }}">{{ __('Show all SKUs') }}</a></p>
        @endif
        <x-ui.table :headers="[__('Product'), __('SKU'), __('Warehouse'), __('On hand'), __('Reserved'), __('Available'), __('Damaged'), '']" :rows="$rows->isNotEmpty()" :empty="__('No product is fulfilled by Nawy yet. Enrol a product below.')">
            @foreach ($rows as $row)
                <tr class="border-t border-line align-top" data-fbn-listing="{{ $row['listing']->id }}">
                    <td class="px-5 py-3 font-medium">{{ $row['title'] }}
                        <span class="mt-1 flex flex-wrap gap-1">
                            @if ($row['low_stock'])<x-ui.badge tone="warning">{{ __('Low stock') }}</x-ui.badge>@endif
                            @if ($row['long_term'])<x-ui.badge tone="danger">{{ __('Long-term storage') }}</x-ui.badge>@endif
                            @if ($row['unsellable'])<x-ui.badge tone="danger">{{ __('Unsellable') }}</x-ui.badge>@endif
                        </span>
                    </td>
                    <td class="px-5 py-3">{{ $row['sku'] }}</td>
                    <td class="px-5 py-3">
                        @forelse ($row['warehouses'] as $warehouse)
                            <span class="block">{{ $warehouse['code'] }} · {{ $warehouse['available'] }}/{{ $warehouse['on_hand'] }}@if ($warehouse['bin']) <span class="text-xs text-fg-secondary">({{ $warehouse['bin'] }})</span>@endif</span>
                        @empty
                            <span class="text-fg-secondary">{{ __('Not received yet') }}</span>
                        @endforelse
                    </td>
                    <td class="px-5 py-3 tabular-nums">{{ $row['on_hand'] }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $row['reserved'] }}</td>
                    <td class="px-5 py-3 font-semibold tabular-nums">{{ $row['available'] }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $row['damaged'] }}</td>
                    <td class="px-5 py-3">
                        @if ($row['on_hand'] + $row['damaged'] === 0)
                            <form method="POST" action="{{ route('partner.inventory.leave', $row['listing']->id) }}">@csrf
                                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Fulfil it myself') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Enrol a product in Fulfilled by Nawy')" :subtitle="__('Its own stock count goes to zero: Nawy sells the units the warehouse receives.')">
        <form method="POST" action="{{ route('partner.inventory.enrol') }}" class="flex flex-wrap items-end gap-3 px-5 pb-5">
            @csrf
            <div class="min-w-64 flex-1">
                <x-ui.select :label="__('Product')" name="listing_id" :placeholder="__('Choose a product')"
                             :options="$candidates->mapWithKeys(fn ($listing) => [$listing->id => $listing->variant->product->title().' · '.$listing->variant->sku])->all()" />
            </div>
            <x-ui.button type="submit" :disabled="$candidates->isEmpty()">{{ __('Enrol in FBN') }}</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.panel>
