<x-layouts.panel :title="__('Overview — today')" :subtitle="$carrier->name.' · '.__('SLA targets set by Nawy per zone')" :breadcrumbs="[__('Overview'), __('Today')]">
    <x-slot:actions>
        <x-ui.button :href="route('carrier.pickups')">{{ __('Open pickups') }} ({{ $unassigned }})</x-ui.button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Shipments today')" :value="$shipmentsToday" />
        <x-ui.stat-card :label="__('On-time delivery (30 d)')" :value="($score['on_time_rate'] ?? null) === null ? '—' : $score['on_time_rate'].'%'" />
        <x-ui.stat-card :label="__('Delivery success (30 d)')" :value="($score['success_rate'] ?? null) === null ? '—' : $score['success_rate'].'%'" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('COD cash with drivers') }}</span>
            <span class="text-2xl font-bold"><x-ui.money :amount="$codInHand" :currency="$currentCountry->currency" /></span>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Pipeline')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Stage'), __('Count'), __('Oldest update')]">
                @foreach (['label_created' => __('Waiting for a driver'), 'picked_up' => __('Picked up / at hub'), 'out_for_delivery' => __('Out for delivery'), 'failed' => __('Exceptions'), 'rescheduled' => __('Rescheduled'), 'returning_to_origin' => __('Returns to origin'), 'delivered' => __('Delivered')] as $status => $label)
                    @php($row = $pipeline->get($status))
                    <tr class="border-t border-line" data-stage="{{ $status }}">
                        <td class="px-5 py-3">{{ $label }}</td>
                        <td class="px-5 py-3">{{ $row?->total ?? 0 }}</td>
                        <td class="px-5 py-3 text-xs">{{ $row?->oldest ? \Illuminate\Support\Carbon::parse($row->oldest)->diffForHumans() : '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Nawy scorecard (30 d)')">
            <dl class="flex flex-col gap-2 text-sm">
                <div class="flex justify-between"><dt>{{ __('Overall') }}</dt><dd class="font-semibold" data-score>{{ $score['score'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Attempts') }}</dt><dd>{{ $score['attempts'] ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('SLA breaches') }}</dt><dd>{{ $score['sla_breaches'] ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Claims open') }}</dt><dd>{{ $openClaims }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Active drivers') }}</dt><dd>{{ $agentsActive }} / {{ $agentsTotal }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('SLA by zone (30 d)')" flush>
        <x-ui.table :headers="[__('Zone'), __('Delivered'), __('On time'), __('Late'), __('Failed'), __('Open past SLA')]" :rows="$slaByZone->isNotEmpty()" :empty="__('No deliveries in the last 30 days.')">
            @foreach ($slaByZone as $zone)
                <tr class="border-t border-line" data-sla-zone="{{ $zone['zone'] }}">
                    <td class="px-5 py-3 font-medium">{{ $zone['zone'] }}</td>
                    <td class="px-5 py-3">{{ $zone['delivered'] }}</td>
                    <td class="px-5 py-3">{{ $zone['on_time'] }}@if ($zone['on_time_percent'] !== null) <span class="text-xs text-fg-secondary">({{ $zone['on_time_percent'] }}%)</span>@endif</td>
                    <td class="px-5 py-3">{{ $zone['late'] }}</td>
                    <td class="px-5 py-3">{{ $zone['failed'] }}</td>
                    <td class="px-5 py-3">{{ $zone['open_overdue'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
