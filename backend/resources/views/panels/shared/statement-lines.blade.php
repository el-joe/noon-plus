{{-- Statement lines (seller statement 100:17344, admin batch 98:4242). --}}
<x-ui.card :title="__('Statement lines')" flush>
    <x-ui.table :headers="[__('Line'), __('Basis'), __('Amount')]">
        @foreach ($statement['lines'] as $line)
            <tr class="border-t border-line">
                <td class="px-5 py-3 font-medium">{{ $line['label'] }}</td>
                <td class="px-5 py-3 text-xs text-fg-secondary">{{ $line['basis'] }}</td>
                <td class="px-5 py-3"><x-ui.money :amount="(string) $line['amount']->getAmount()" :currency="$payout->currency" /></td>
            </tr>
        @endforeach
        <tr class="border-t border-line font-semibold">
            <td class="px-5 py-3">{{ __('Net payout') }}</td><td></td>
            <td class="px-5 py-3"><x-ui.money :amount="$payout->total" :currency="$payout->currency" /></td>
        </tr>
    </x-ui.table>
</x-ui.card>

<x-ui.card :title="__('Order lines (:count)', ['count' => $statement['orders']])" flush>
    <x-ui.table :headers="[__('Shipment'), __('Gross'), __('Commission'), __('VAT on fees'), __('Net')]" :rows="$statement['settlements']->isNotEmpty()">
        @foreach ($statement['settlements'] as $settlement)
            <tr class="border-t border-line" data-settlement="{{ $settlement->id }}">
                <td class="px-5 py-3">{{ $settlement->subOrder->number }}@if ($settlement->is_cod)<span class="ms-1 text-xs text-fg-secondary">{{ __('COD') }}</span>@endif</td>
                <td class="px-5 py-3"><x-ui.money :amount="$settlement->gross" :currency="$settlement->currency" /></td>
                <td class="px-5 py-3"><x-ui.money :amount="$settlement->commission" :currency="$settlement->currency" /></td>
                <td class="px-5 py-3"><x-ui.money :amount="$settlement->commission_vat" :currency="$settlement->currency" /></td>
                <td class="px-5 py-3"><x-ui.money :amount="$settlement->net" :currency="$settlement->currency" /></td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
