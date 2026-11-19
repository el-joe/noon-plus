@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Payouts')" :subtitle="__('Vendor statements in :country · maker-checker approval required · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Payouts')]">
    <x-slot:actions>
        @can('payouts.process')
            <form method="POST" action="{{ route('admin.finance.payouts.generate') }}">@csrf<x-ui.button type="submit">{{ __('Generate statements now') }}</x-ui.button></form>
        @endcan
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('To pay')" :value="$fmt->format((string) $kpis['pending']->getAmount(), $country->currency)" :delta="trans_choice(':count statement|:count statements', $kpis['pending_count'])" />
        <x-ui.stat-card :label="__('On hold')" :value="$fmt->format((string) $kpis['on_hold']->getAmount(), $country->currency)" :delta="trans_choice(':count statement|:count statements', $kpis['on_hold_count'])" trend="down" />
        <x-ui.stat-card :label="__('COD outstanding')" :value="$fmt->format((string) $kpis['cod_outstanding']->getAmount(), $country->currency)" :delta="trans_choice(':count settlement open|:count settlements open', $kpis['cod_open'])" trend="down" />
    </section>

    <nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Finance sections') }}">
        <a href="{{ route('admin.finance.payouts') }}" class="border-b-2 border-brand px-2 pb-2 font-medium">{{ __('Payout lines') }}</a>
        @can('cod_settlements.view')
            <a href="{{ route('admin.finance.cod-settlements') }}" class="px-2 pb-2 text-fg-secondary">{{ __('COD settlement') }}</a>
        @endcan
        <a href="{{ route('admin.finance.payouts', ['status' => 'on_hold']) }}" class="px-2 pb-2 text-fg-secondary">{{ __('Holds') }}</a>
        @can('ledger.view')<a href="{{ route('admin.finance.ledger') }}" class="px-2 pb-2 text-fg-secondary">{{ __('Ledger') }}</a>@endcan
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Statement'), __('Payee'), __('Period'), __('Amount'), __('Schedule'), __('Status')]" :rows="$payouts->isNotEmpty()" :empty="__('No statements yet.')">
            @foreach ($payouts as $payout)
                <tr class="border-t border-line" data-payout="{{ $payout->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.payouts.show', $payout->id) }}">{{ $payout->number }}</a></td>
                    <td class="px-5 py-3">{{ $payout->vendor->store_name }}<span class="block text-xs text-fg-secondary">V-{{ $payout->vendor_id }}</span></td>
                    <td class="px-5 py-3 text-xs">{{ $payout->period_start->format('j M') }} – {{ $payout->period_end->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$payout->total" :currency="$payout->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ ucfirst($payout->schedule->value) }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$payout->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $payouts->links() }}
</x-layouts.panel>
