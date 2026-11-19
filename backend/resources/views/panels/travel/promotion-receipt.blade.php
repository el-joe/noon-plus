@php($net = app(\App\Shared\Money\MoneyFactory::class)->of((string) $promotion->amount, $promotion->currency)->minus(app(\App\Shared\Money\MoneyFactory::class)->of((string) $promotion->vat_amount, $promotion->currency)))
<x-layouts.panel :title="__('Receipt :number', ['number' => $promotion->number])" :subtitle="__('Tax receipt for a paid travel promotion')" :breadcrumbs="[__('Travel Partner'), __('Promotions'), $promotion->number]">
    <x-ui.card data-receipt>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-fg-secondary">{{ __('Billed to') }}</dt><dd class="font-medium">{{ $promotion->vendor->store_name }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Paid on') }}</dt><dd>{{ $payment->captured_at?->setTimezone($country->timezone)->format('j M Y, H:i') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Trip') }}</dt><dd>{{ $promotion->package->localizedTitle() }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Option') }}</dt><dd>{{ $promotion->product?->localizedName() }} · {{ trans_choice(':count day|:count days', $promotion->duration_days, ['count' => $promotion->duration_days]) }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Payment reference') }}</dt><dd class="font-mono text-xs">{{ $payment->reference }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Payment method') }}</dt><dd>{{ $payment->method->label() }}</dd></div>
        </dl>
        <table class="mt-6 w-full text-sm">
            <tr class="border-t border-line"><td class="py-2">{{ __('Amount before VAT') }}</td><td class="py-2 text-end"><x-ui.money :amount="(string) $net->getAmount()" :currency="$promotion->currency" /></td></tr>
            <tr class="border-t border-line"><td class="py-2">{{ __('VAT (:rate%)', ['rate' => rtrim(rtrim((string) $country->tax_rate, '0'), '.')]) }}</td><td class="py-2 text-end" data-vat><x-ui.money :amount="(string) $promotion->vat_amount" :currency="$promotion->currency" /></td></tr>
            <tr class="border-t border-line font-bold"><td class="py-2">{{ __('Total paid') }}</td><td class="py-2 text-end" data-total><x-ui.money :amount="(string) $promotion->amount" :currency="$promotion->currency" /></td></tr>
            @if (bccomp((string) $payment->refunded_amount, '0', 4) > 0)
                <tr class="border-t border-line text-fg-danger"><td class="py-2">{{ __('Refunded') }}</td><td class="py-2 text-end"><x-ui.money :amount="(string) $payment->refunded_amount" :currency="$promotion->currency" /></td></tr>
            @endif
        </table>
    </x-ui.card>
</x-layouts.panel>
