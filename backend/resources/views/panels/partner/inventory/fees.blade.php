<x-layouts.panel :title="__('FBN fees')" :subtitle="__('What Fulfilled by Nawy costs, a preview per product and what you were charged')" :breadcrumbs="[__('Inventory & warehouses'), __('FBN fees')]">
    @include('panels.partner.inventory._nav')

    <div class="grid gap-6 xl:grid-cols-2">
        <x-ui.card :title="__('Fee preview')" :subtitle="__('Pick a product to see its fees per unit.')">
            <form method="GET" class="flex flex-wrap items-end gap-3 px-5 pb-4">
                <div class="min-w-64 flex-1">
                    <x-ui.select :label="__('Product')" name="listing_id" :selected="$preview['listing']->id ?? null" :placeholder="__('Choose a product')"
                                 :options="$listings->mapWithKeys(fn ($listing) => [$listing->id => $listing->variant->product->title().' · '.$listing->variant->sku])->all()" />
                </div>
                <x-ui.button type="submit" variant="outline">{{ __('Preview') }}</x-ui.button>
            </form>
            @if ($preview)
                <dl class="grid grid-cols-2 gap-2 px-5 pb-5 text-sm" data-fbn-preview>
                    <dt class="text-fg-secondary">{{ __('Selling price') }}</dt><dd><x-ui.money :amount="$preview['price']" :currency="$currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Billable weight') }}</dt><dd>{{ number_format($preview['grams']) }} g</dd>
                    <dt class="text-fg-secondary">{{ __('Volume per unit') }}</dt><dd>{{ rtrim(rtrim($preview['cubic_feet'], '0'), '.') }} ft³</dd>
                    <dt class="text-fg-secondary">{{ __('Fulfilment fee per unit sold') }}</dt><dd class="font-semibold" data-outbound-fee>{{ $preview['outbound']->getAmount() }} {{ $currency }}</dd>
                    <dt class="text-fg-secondary">{{ __('Storage per unit per month') }}</dt><dd>{{ $preview['storage']->getAmount() }} {{ $currency }}</dd>
                    <dt class="text-fg-secondary">{{ __('Removal of one unit') }}</dt><dd>{{ $preview['removal']->getAmount() }} {{ $currency }}</dd>
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Fee table (:currency)', ['currency' => $currency])" flush>
            <x-ui.table :headers="[__('Billable weight up to'), __('Price ≤ :threshold', ['threshold' => $fees['outbound_price_threshold']]), __('Price above')]">
                @foreach ($fees['outbound_tiers'] as $tier)
                    <tr class="border-t border-line"><td class="px-5 py-2">{{ number_format($tier['max_grams']) }} g</td><td class="px-5 py-2 tabular-nums">{{ $tier['low'] }}</td><td class="px-5 py-2 tabular-nums">{{ $tier['high'] }}</td></tr>
                @endforeach
            </x-ui.table>
            <ul class="flex flex-col gap-1 px-5 py-4 text-sm">
                <li>{{ __('Heavier: + :amount per extra kg', ['amount' => $fees['outbound_extra_per_kg']]) }}</li>
                <li>{{ __('Storage: :amount per cubic foot per month', ['amount' => $fees['storage_per_cubic_foot']]) }}</li>
                <li>{{ __('Long-term storage: :amount per cubic foot per month', ['amount' => $fees['long_term_per_cubic_foot']]) }}</li>
                <li>{{ __('Unsellable storage: :amount per cubic foot per month', ['amount' => $fees['unsellable_per_cubic_foot']]) }}</li>
                <li>{{ __('Removal: :per_unit per unit + :per_kg per kg, at least :minimum', ['per_unit' => $fees['removal_per_unit'], 'per_kg' => $fees['removal_per_kg'], 'minimum' => $fees['removal_minimum']]) }}</li>
            </ul>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Monthly storage invoices')" flush>
        <x-ui.table :headers="[__('Invoice'), __('Month'), __('Average volume'), __('Storage'), __('Long-term'), __('Unsellable'), __('Total')]" :rows="$invoices->isNotEmpty()" :empty="__('No storage invoices yet.')">
            @foreach ($invoices as $invoice)
                <tr class="border-t border-line" data-storage-invoice="{{ $invoice->id }}">
                    <td class="px-5 py-3 font-medium">{{ $invoice->number }}</td>
                    <td class="px-5 py-3">{{ $invoice->period_start->format('Y-m') }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $invoice->storage_cubic_feet }} ft³</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$invoice->storage_fee" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$invoice->long_term_fee" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$invoice->unsellable_fee" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3 font-semibold"><x-ui.money :amount="$invoice->total" :currency="$invoice->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-ui.card :title="__('Fulfilment fees on orders')" flush>
            <x-ui.table :headers="[__('Order'), __('Settled'), __('FBN fee')]" :rows="$orderFees->isNotEmpty()" :empty="__('No FBN orders settled yet.')">
                @foreach ($orderFees as $settlement)
                    <tr class="border-t border-line"><td class="px-5 py-3">{{ $settlement->subOrder?->number }}</td><td class="px-5 py-3">{{ $settlement->settled_at?->toDateString() }}</td><td class="px-5 py-3"><x-ui.money :amount="$settlement->fbn_fees" :currency="$currency" /></td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Removal fees')" flush>
            <x-ui.table :headers="[__('Removal'), __('Date'), __('Fee')]" :rows="$removalFees->isNotEmpty()" :empty="__('No removal fees yet.')">
                @foreach ($removalFees as $item)
                    <tr class="border-t border-line"><td class="px-5 py-3">{{ $item->description }}</td><td class="px-5 py-3">{{ $item->created_at->toDateString() }}</td><td class="px-5 py-3"><x-ui.money :amount="ltrim((string) $item->amount, '-')" :currency="$currency" /></td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.panel>
