<x-layouts.panel :title="__('Payments under review')" :subtitle="__('Captures for another amount than the order in :country — reconcile, then resolve', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Payments under review')]">
    @include('panels.admin.refunds._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Payment'), __('For'), __('Method'), __('Expected'), __('Captured'), __('Held since (Oman)')]" :rows="$payments->isNotEmpty()" :empty="__('No payment is waiting for review.')">
            @foreach ($payments as $payment)
                <tr class="border-t border-line" data-payment="{{ $payment->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.payments-review.show', $payment->id) }}">{{ $payment->reference }}</a></td>
                    <td class="px-5 py-3">{{ $payment->order?->number ?? str_replace('_', ' ', (string) $payment->payable_type).' #'.$payment->payable_id }}</td>
                    <td class="px-5 py-3">{{ ucfirst($payment->method->value) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $payment->amount" :currency="$payment->currency" /></td>
                    <td class="px-5 py-3 text-fg-warning"><x-ui.money :amount="(string) ($payment->captured_amount ?? '0')" :currency="$payment->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $payment->updated_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $payments->links() }}
</x-layouts.panel>
