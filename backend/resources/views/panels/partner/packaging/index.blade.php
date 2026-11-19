<x-layouts.panel :title="__('Packaging supplies')" :subtitle="__('Order Nawy packaging; the cost and delivery fee are deducted from your next payout')" :breadcrumbs="[__('Inventory & warehouses'), __('Packaging supplies')]">
    <form method="POST" action="{{ route('partner.packaging.store') }}" data-packaging-form>
        @csrf
        <div class="grid gap-6 xl:grid-cols-3">
            <x-ui.card :title="__('Catalogue')" flush class="xl:col-span-2">
                <x-ui.table :headers="[__('Item'), __('Unit cost'), __('Order limits'), __('Quantity')]" :rows="$supplies->isNotEmpty()" :empty="__('No packaging is offered in this country yet.')">
                    @foreach ($supplies as $supply)
                        <tr class="border-t border-line" data-supply="{{ $supply->sku }}">
                            <td class="px-5 py-3 font-medium">{{ $supply->name() }}<span class="block text-xs text-fg-secondary">{{ $supply->sku }} · {{ app()->getLocale() === 'ar' ? $supply->description_ar : $supply->description_en }}</span></td>
                            <td class="px-5 py-3"><x-ui.money :amount="(string) $supply->unit_cost" :currency="$deliveryFee->getCurrency()->getCurrencyCode()" /></td>
                            <td class="px-5 py-3 text-xs">{{ $supply->min_quantity }} – {{ $supply->max_quantity ?? '∞' }}</td>
                            <td class="px-5 py-3"><input type="number" name="quantities[{{ $supply->id }}]" min="0" value="{{ old('quantities.'.$supply->id, 0) }}" aria-label="{{ __('Quantity of :item', ['item' => $supply->name()]) }}" class="h-9 w-24 rounded-lg border border-line bg-surface px-2 text-sm"></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card :title="__('Delivery')">
                <div class="flex flex-col gap-3">
                    <x-ui.input :label="__('Street')" name="address[street]" id="address-street" :value="$address['street'] ?? null" required />
                    <x-ui.input :label="__('Building')" name="address[building]" id="address-building" :value="$address['building'] ?? null" />
                    <x-ui.input :label="__('Area')" name="address[area]" id="address-area" :value="$address['area'] ?? null" required />
                    <x-ui.input :label="__('City')" name="address[city]" id="address-city" :value="$address['city'] ?? null" required />
                    <x-ui.input :label="__('Phone')" name="address[phone]" id="address-phone" />
                    <x-ui.textarea :label="__('Notes')" name="notes" :rows="2" />
                    <p class="text-sm text-fg-secondary">{{ __('Delivery fee') }}: <x-ui.money :amount="(string) $deliveryFee->getAmount()" :currency="$deliveryFee->getCurrency()->getCurrencyCode()" /></p>
                    <div><x-ui.button type="submit" :disabled="$supplies->isEmpty()">{{ __('Place order') }}</x-ui.button></div>
                </div>
            </x-ui.card>
        </div>
    </form>

    <x-ui.card :title="__('Your packaging orders')" flush>
        <x-ui.table :headers="[__('Order'), __('Items'), __('Total'), __('Placed'), __('Status')]" :rows="$orders->isNotEmpty()" :empty="__('No packaging orders yet.')">
            @foreach ($orders as $order)
                <tr class="border-t border-line" data-packaging-order="{{ $order->number }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('partner.packaging.show', $order->id) }}">{{ $order->number }}</a></td>
                    <td class="px-5 py-3">{{ $order->items_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $order->total" :currency="$order->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $order->created_at?->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$order->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $orders->links() }}
</x-layouts.panel>
