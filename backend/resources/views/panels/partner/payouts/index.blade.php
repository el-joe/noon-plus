@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Payouts')" :subtitle="__('Statements of :store · paid :schedule after Nawy approval', ['store' => $vendor->store_name, 'schedule' => $vendor->payout_schedule ?? 'weekly'])" :breadcrumbs="[__('Seller Center'), __('Payouts')]">
    @if ($awaitingCod > 0)
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">
            {{ trans_choice(':count cash-on-delivery order is waiting for the courier\'s cash remittance before it can be paid out.|:count cash-on-delivery orders are waiting for the courier\'s cash remittance before they can be paid out.', $awaitingCod) }}
        </div>
    @endif

    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Awaiting COD remittance')" :value="$fmt->format((string) $balances['pending']->getAmount(), $country->currency)" />
        <x-ui.stat-card :label="__('Next statement')" :value="$fmt->format((string) $balances['spendable']->getAmount(), $country->currency)" />
        <x-ui.stat-card :label="__('Being paid')" :value="$fmt->format((string) $balances['reserved']->getAmount(), $country->currency)" />
    </section>

    <x-ui.card :title="__('Statements')" flush>
        <x-ui.table :headers="[__('Statement'), __('Period'), __('Net'), __('Status')]" :rows="$payouts->isNotEmpty()" :empty="__('No statements yet. Completed orders appear on your next statement.')">
            @foreach ($payouts as $payout)
                <tr class="border-t border-line" data-payout="{{ $payout->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('partner.payouts.show', $payout->id) }}">{{ $payout->number }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $payout->period_start->format('j M') }} – {{ $payout->period_end->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$payout->total" :currency="$payout->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$payout->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $payouts->links() }}
</x-layouts.panel>
