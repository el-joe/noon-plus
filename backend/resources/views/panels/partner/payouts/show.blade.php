@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Statement :number', ['number' => $payout->number])"
                 :subtitle="__(':from – :to (:zone) · all amounts in :currency incl. VAT where shown', ['from' => $payout->period_start->format('j M'), 'to' => $payout->period_end->format('j M Y'), 'zone' => $country->timezone, 'currency' => $payout->currency])"
                 :breadcrumbs="[__('Payouts'), __('Statements'), $payout->number]">
    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Gross sales')" :value="$fmt->format((string) $statement['gross']->getAmount(), $payout->currency)" :delta="trans_choice(':count order|:count orders', $statement['orders'])" />
        <x-ui.stat-card :label="__('Nawy fees')" :value="$fmt->format((string) $statement['fees']->getAmount(), $payout->currency)" trend="down" />
        <x-ui.stat-card :label="__('Adjustments')" :value="$fmt->format((string) $statement['adjustments']->getAmount(), $payout->currency)" />
        <x-ui.stat-card :label="__('Net payout')" :value="$fmt->format((string) $statement['net']->getAmount(), $payout->currency)" />
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            @include('panels.shared.statement-lines')
        </div>
        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Payout status')">
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-fg-secondary">{{ __('Statement') }}</dt><dd class="text-end"><x-ui.status-badge :status="$payout->status" /></dd>
                    <dt class="text-fg-secondary">{{ __('Bank transfer') }}</dt><dd class="text-end">{{ $payout->completed_at ? __('Sent :date', ['date' => $payout->completed_at->setTimezone($country->timezone)->format('j M')]) : '—' }}</dd>
                    @if ($payout->bank_reference)<dt class="text-fg-secondary">{{ __('Payout reference') }}</dt><dd class="text-end">{{ $payout->bank_reference }}</dd>@endif
                    @if ($payout->hold_reason)<dt class="text-fg-secondary">{{ __('On hold') }}</dt><dd class="text-end">{{ $payout->hold_reason }}</dd>@endif
                </dl>
            </x-ui.card>
            <x-ui.card :title="__('Previous statements')" flush>
                <x-ui.table :headers="[__('Period'), __('Net'), '']" :rows="$previous->isNotEmpty()" :empty="__('None yet.')">
                    @foreach ($previous as $earlier)
                        <tr class="border-t border-line">
                            <td class="px-5 py-3 text-xs">{{ $earlier->period_start->format('j M') }} – {{ $earlier->period_end->format('j M') }}</td>
                            <td class="px-5 py-3"><x-ui.money :amount="$earlier->total" :currency="$earlier->currency" /></td>
                            <td class="px-5 py-3"><x-ui.status-badge :status="$earlier->status" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
