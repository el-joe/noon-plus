<x-layouts.panel :title="__('Order :number', ['number' => $order->number])"
                 :subtitle="__('Placed :local :country (:oman Oman) · :payment · :count shipments', [
                     'local' => $clock->inCountry($country, $order->placed_at)->format('j M Y, H:i'),
                     'country' => $country->iso2,
                     'oman' => $clock->inAdminTime($order->placed_at)->format('H:i'),
                     'payment' => strtoupper($order->payment_method),
                     'count' => $order->subOrders->count(),
                 ])"
                 :breadcrumbs="[__('Orders & disputes'), __('Orders'), $order->number]">
    @if (\App\Domains\Orders\Support\InvoicePdf::isReady($order))
        <x-slot:actions>
            <x-ui.button variant="outline" :href="route('admin.orders.invoice-pdf', $order->id)">{{ __('Download invoice (PDF)') }}</x-ui.button>
        </x-slot:actions>
    @endif
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <section class="grid gap-4 sm:grid-cols-2">
                <x-ui.stat-card :label="__('Order total')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format($order->grand_total, $order->currency)" />
                <x-ui.stat-card :label="__('Status')" :value="str_replace('_', ' ', ucfirst($order->status->value))" />
            </section>

            @foreach ($order->subOrders as $subOrder)
                <x-ui.card :title="__('Sub-order :number · :seller', ['number' => $subOrder->number, 'seller' => $subOrder->vendor->store_name])" flush data-sub-order="{{ $subOrder->id }}">
                    <x-slot:actions><x-ui.status-badge :status="$subOrder->status" /></x-slot:actions>
                    <x-ui.table :headers="[__('Item'), __('Qty'), __('Price'), __('Status')]">
                        @foreach ($subOrder->items as $item)
                            <tr class="border-t border-line">
                                <td class="px-5 py-3"><span class="font-medium">{{ $item->title }}</span><span class="block text-xs text-fg-secondary">{{ $item->sku }}</span></td>
                                <td class="px-5 py-3">{{ $item->quantity }}</td>
                                <td class="px-5 py-3"><x-ui.money :amount="$item->line_total" :currency="$order->currency" /></td>
                                <td class="px-5 py-3"><x-ui.status-badge :status="$item->fulfillment_status" /></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endforeach

            <x-ui.card :title="__('Payment & settlement')">
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-fg-secondary">{{ __('Subtotal') }}</dt><dd class="text-end"><x-ui.money :amount="$order->subtotal" :currency="$order->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Discounts') }}</dt><dd class="text-end"><x-ui.money :amount="$order->discount_total" :currency="$order->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Shipping') }}</dt><dd class="text-end"><x-ui.money :amount="$order->shipping_total" :currency="$order->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('COD fee') }}</dt><dd class="text-end"><x-ui.money :amount="$order->cod_fee" :currency="$order->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('Warranty plans') }}</dt><dd class="text-end"><x-ui.money :amount="$order->warranty_total" :currency="$order->currency" /></dd>
                    <dt class="text-fg-secondary">{{ __('VAT :rate% (included)', ['rate' => $order->tax_rate]) }}</dt><dd class="text-end"><x-ui.money :amount="$order->tax_included" :currency="$order->currency" /></dd>
                    <dt class="font-semibold">{{ __('Total') }}</dt><dd class="text-end font-semibold"><x-ui.money :amount="$order->grand_total" :currency="$order->currency" /></dd>
                </dl>
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-6">
            @foreach ($order->subOrders as $subOrder)
                <x-ui.state-timeline :title="__('Sub-order :number', ['number' => $subOrder->number])" :subtitle="__('Every transition is logged with the actor · Oman time')" :steps="$timelines[$subOrder->id]" />

                @if ($moves[$subOrder->id] !== [] || in_array(\App\Domains\Orders\Enums\SubOrderStatus::Cancelled, $subOrder->status->allowedNext(), true))
                    <x-ui.card :title="__('Admin actions · :number', ['number' => $subOrder->number])">
                        @can('orders.status')
                            @if ($moves[$subOrder->id] !== [])
                                <form method="POST" action="{{ route('admin.orders.move', $subOrder->id) }}" class="flex flex-col gap-3">
                                    @csrf
                                    <x-ui.select :label="__('Change state')" name="to" :id="'to-'.$subOrder->id"
                                                 :options="collect($moves[$subOrder->id])->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
                                    <x-ui.textarea :label="__('Reason (required)')" name="reason" :id="'reason-'.$subOrder->id" rows="2" required />
                                    <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                                </form>
                            @endif
                        @endcan
                        @can('orders.force_cancel')
                            @if (in_array(\App\Domains\Orders\Enums\SubOrderStatus::Cancelled, $subOrder->status->allowedNext(), true))
                                <form method="POST" action="{{ route('admin.orders.force-cancel', $subOrder->id) }}" class="mt-4 flex flex-col gap-3 border-t border-line pt-4">
                                    @csrf
                                    <x-ui.textarea :label="__('Force-cancel reason')" name="reason" :id="'cancel-reason-'.$subOrder->id" rows="2" required />
                                    <div><x-ui.button type="submit" variant="danger">{{ __('Force-cancel :number', ['number' => $subOrder->number]) }}</x-ui.button></div>
                                </form>
                            @endif
                        @endcan
                    </x-ui.card>
                @endif
            @endforeach

            @can('orders.refund')
                <x-ui.card :title="__('Manual refund')" :subtitle="__('Goes to the refunds queue; another admin approves it.')">
                    <form method="POST" action="{{ route('admin.refunds.store', $order->id) }}" class="flex flex-col gap-3" data-manual-refund>
                        @csrf
                        <x-ui.input :label="__('Amount (:currency)', ['currency' => $order->currency])" name="amount" inputmode="decimal" required />
                        <x-ui.select :label="__('Shipment')" name="sub_order_id" :placeholder="__('Whole order')" :options="$order->subOrders->pluck('number', 'id')->all()" />
                        <x-ui.select :label="__('Refund to')" name="destination" :options="['original' => __('Original payment method'), 'wallet' => __('Nawy Credits')]" />
                        <x-ui.input :label="__('Reason')" name="reason" required />
                        <div><x-ui.button type="submit" variant="outline">{{ __('Request refund') }}</x-ui.button></div>
                    </form>
                </x-ui.card>
            @endcan

            <x-ui.card :title="__('Customer & delivery')">
                @php($address = $order->shipping_address)
                <dl class="grid gap-1 text-sm">
                    <dd>{{ $address['name'] ?? '' }} {{ isset($address['phone']) ? '· '.$address['phone'] : '' }}</dd>
                    <dd class="text-fg-secondary">{{ collect([$address['street'] ?? null, $address['area'] ?? null, $address['city'] ?? null])->filter()->implode(', ') }}</dd>
                </dl>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
