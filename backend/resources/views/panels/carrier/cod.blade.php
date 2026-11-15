<x-layouts.panel :title="__('COD reconciliation')" :subtitle="__('Cash collected by drivers → settled with Nawy per driver and day')" :breadcrumbs="[__('Commercial'), __('COD & reconciliation')]">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([__('Collected today') => $collectedToday, __('Cash in hand') => $cashInHand, __('Settled (recent)') => $remitted] as $label => $amount)
            <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
                <span class="text-sm text-fg-secondary">{{ $label }}</span>
                <span class="text-2xl font-bold"><x-ui.money :amount="$amount" :currency="$currentCountry->currency" /></span>
            </div>
        @endforeach
        <x-ui.stat-card :label="__('Disputed settlements')" :value="$disputed" />
    </div>

    <x-ui.card :title="__('By driver')" flush>
        <x-ui.table :headers="[__('Driver'), __('COD stops not settled'), __('Cash in hand')]" :rows="$agents->isNotEmpty()" :empty="__('No drivers yet.')">
            @foreach ($agents as $agent)
                <tr class="border-t border-line" data-cod-agent="{{ $agent->id }}">
                    <td class="px-5 py-3 font-medium">{{ $agent->name }}<p class="text-xs text-fg-secondary">{{ $agent->vehicle_type }} {{ $agent->vehicle_plate }}</p></td>
                    <td class="px-5 py-3">{{ $inHand->get($agent->user_id)?->stops ?? 0 }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) ($inHand->get($agent->user_id)?->total ?? '0')" :currency="$currentCountry->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Settlements')" flush>
        @php($names = $agents->pluck('name', 'user_id'))
        <x-ui.table :headers="[__('Settlement'), __('Driver'), __('Day'), __('Expected'), __('Remitted'), __('Status')]" :rows="$settlements->isNotEmpty()" :empty="__('No settlements yet.')">
            @foreach ($settlements as $settlement)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $settlement->number }}</td>
                    <td class="px-5 py-3">{{ $names->get($settlement->agent_user_id, '—') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $settlement->business_date->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$settlement->expected_amount" :currency="$settlement->currency" /></td>
                    <td class="px-5 py-3">@if ($settlement->remitted_amount !== null)<x-ui.money :amount="$settlement->remitted_amount" :currency="$settlement->currency" />@else — @endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$settlement->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    <p class="text-xs text-fg-secondary">{{ __('Nawy finance marks a settlement settled when the cash is received; shortages are disputed and stay owed by the driver.') }}</p>
</x-layouts.panel>
