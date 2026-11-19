<x-layouts.panel :title="__('Order :number', ['number' => $subOrder->number])"
                 :subtitle="__('Placed :date (:zone)', ['date' => $subOrder->order->placed_at->setTimezone($country->timezone)->format('j M, H:i'), 'zone' => $country->timezone])"
                 :breadcrumbs="[__('Orders'), $subOrder->number]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('partner.orders.packing-slip', $subOrder->id)" target="_blank">{{ __('Print packing slip') }}</x-ui.button>
        @if (\App\Domains\Delivery\Models\Shipment::query()->where('sub_order_id', $subOrder->id)->exists())
            <x-ui.button variant="outline" :href="route('partner.orders.label', $subOrder->id)" target="_blank" data-parcel-label>{{ __('Print parcel label') }}</x-ui.button>
        @endif
        @if (\App\Domains\Orders\Support\InvoicePdf::isReady($subOrder->order))
            <x-ui.button variant="outline" :href="route('partner.orders.invoice-pdf', $subOrder->id)">{{ __('Download invoice (PDF)') }}</x-ui.button>
        @endif
        @if (app(\App\Domains\Fulfilment\Support\FbnOrders::class)->isWarehouseFulfilled($subOrder) && $subOrder->status->isBeforeShipping())
            <x-ui.badge tone="info" data-fbn-order>{{ __('Fulfilled by Nawy — the warehouse ships this order') }}</x-ui.badge>
        @endif
        @if ($nextStep)
            <form method="POST" action="{{ route('partner.orders.advance', $subOrder->id) }}">
                @csrf
                <input type="hidden" name="to" value="{{ $nextStep->value }}">
                <x-ui.button type="submit">{{ $nextStepLabel }}</x-ui.button>
            </form>
        @endif
    </x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Items')" flush>
                <x-ui.table :headers="[__('Item'), __('SKU'), __('Qty'), __('Price'), __('Status')]">
                    @foreach ($subOrder->items as $item)
                        <tr class="border-t border-line">
                            <td class="px-5 py-3 font-medium">{{ $item->title }}
                                @if (! empty($item->measurements))
                                    <span class="mt-1 block text-xs font-normal text-fg-secondary" data-measurements>{{ __('Made to order') }}: {{ collect($item->measurements)->map(fn ($value, $key) => str_replace('_', ' ', $key).' '.$value)->implode(' · ') }}@if ($item->measurement_note) — “{{ $item->measurement_note }}”@endif</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-xs">{{ $item->sku }}</td>
                            <td class="px-5 py-3">{{ $item->quantity }}</td>
                            <td class="px-5 py-3"><x-ui.money :amount="$item->line_total" :currency="$country->currency" /></td>
                            <td class="px-5 py-3"><x-ui.status-badge :status="$item->fulfillment_status" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card :title="__('Customer & delivery')">
                @php($address = $subOrder->order->shipping_address)
                <dl class="grid gap-1 text-sm text-fg-secondary">
                    <dd>{{ $address['name'] ?? '' }} · {{ __('contact hidden until shipped') }}</dd>
                    <dd>{{ collect([$address['street'] ?? null, $address['area'] ?? null, $address['city'] ?? null])->filter()->implode(', ') }}</dd>
                    <dd>{{ __('Delivery: :method', ['method' => str_replace('_', ' ', $subOrder->shipping_method ?? 'standard')]) }}</dd>
                    <dd>{{ __('Payment: :method', ['method' => strtoupper($subOrder->order->payment_method)]) }}</dd>
                </dl>
            </x-ui.card>

            <x-ui.card :title="__('Totals')">
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-fg-secondary">{{ __('Items') }}</dt><dd class="text-end"><x-ui.money :amount="$subOrder->subtotal" :currency="$country->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Discount') }}</dt><dd class="text-end"><x-ui.money :amount="$subOrder->discount_total" :currency="$country->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Shipping charged') }}</dt><dd class="text-end"><x-ui.money :amount="$subOrder->shipping_total" :currency="$country->currency" /></dd>
                    <dt class="font-semibold">{{ __('Total') }}</dt><dd class="text-end font-semibold"><x-ui.money :amount="$subOrder->total" :currency="$country->currency" /></dd>
                </dl>
            </x-ui.card>

            @if ($canCancel)
                <x-ui.card :title="__('Cancel this order')" :subtitle="__('Cancelling adds a :points-point strike to your seller score and refunds the customer.', ['points' => $strikePoints])">
                    <form method="POST" action="{{ route('partner.orders.cancel', $subOrder->id) }}" class="flex flex-col gap-3">
                        @csrf
                        <x-ui.textarea :label="__('Reason (sent to the customer)')" name="reason" required />
                        <div><x-ui.button type="submit" variant="danger">{{ __('Cancel order') }}</x-ui.button></div>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <x-ui.state-timeline :title="__('Sub-order history')" :subtitle="__('Every transition is logged with the actor')" :steps="$steps" />
    </div>
</x-layouts.panel>
