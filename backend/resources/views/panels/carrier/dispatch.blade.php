<x-layouts.panel :title="__('Live dispatch')" :subtitle="__(':count agents online · :exceptions failed parcels waiting', ['count' => $agents->filter(fn ($agent) => $agent->status->value === 'active')->count(), 'exceptions' => $exceptions])" :breadcrumbs="[__('Dispatch'), __('Live')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('carrier.exceptions')">{{ __('Failed deliveries') }}</x-ui.button>
    </x-slot:actions>
    <p class="text-xs text-fg-secondary">{{ __('The live map needs the real-time client (owner question O1); this board refreshes when you reload.') }}</p>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Drivers & availability')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Driver'), __('Status'), __('Open'), __('Failed today'), __('COD in hand'), __('Last seen')]" :rows="$agents->isNotEmpty()" :empty="__('No drivers yet.')">
                @foreach ($agents as $agent)
                    <tr class="border-t border-line" data-agent="{{ $agent->id }}">
                        <td class="px-5 py-3 font-medium">{{ $agent->name }}<p class="text-xs text-fg-secondary">{{ $agent->vehicle_type }} {{ $agent->vehicle_plate }}</p></td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$agent->status" /></td>
                        <td class="px-5 py-3">{{ $agent->open_count }}</td>
                        <td class="px-5 py-3">@if ($agent->failed_today >= 2)<x-ui.badge tone="danger">{{ $agent->failed_today }}</x-ui.badge>@else{{ $agent->failed_today }}@endif</td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) ($codByAgent->get($agent->user_id) ?? '0')" :currency="$currentCountry->currency" /></td>
                        <td class="px-5 py-3 text-xs">{{ $agent->last_seen_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Agents needing attention')">
            <ul class="flex flex-col gap-2 text-sm">
                @forelse ($agents->filter(fn ($agent) => $agent->failed_today >= 2) as $agent)
                    <li class="flex justify-between"><span>{{ $agent->name }}</span><x-ui.badge tone="danger">{{ __(':n failed today', ['n' => $agent->failed_today]) }}</x-ui.badge></li>
                @empty
                    <li class="text-fg-secondary">{{ __('Everyone is on track.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('On the road')" flush>
        <x-ui.table :headers="[__('Assignment'), __('Driver'), __('Area'), __('SLA due'), __('Status')]" :rows="$open->isNotEmpty()" :empty="__('Nothing on the road.')">
            @foreach ($open as $assignment)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-medium">{{ $assignment->number }}</td>
                    <td class="px-5 py-3">{{ $assignment->agent?->name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $assignment->subOrder->order->shipping_address['city'] ?? '' }} · {{ $assignment->subOrder->order->shipping_address['area'] ?? '' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $assignment->sla_due_at?->setTimezone($currentCountry->timezone)->format('j M H:i') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$assignment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
