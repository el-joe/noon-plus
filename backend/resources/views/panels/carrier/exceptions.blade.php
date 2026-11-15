<x-layouts.panel :title="__('Failed deliveries')" :subtitle="__(':count parcels need a decision (max :max attempts)', ['count' => $shipments->count(), 'max' => $maxAttempts])" :breadcrumbs="[__('Monitoring'), __('Failed deliveries')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Shipment'), __('Driver'), __('Reason'), __('Attempts'), __('Status'), __('Next step')]" :rows="$shipments->isNotEmpty()" :empty="__('No failed deliveries.')">
            @foreach ($shipments as $shipment)
                <tr class="border-t border-line align-top" data-exception="{{ $shipment->id }}">
                    <td class="px-5 py-3 font-medium">{{ $shipment->number }}</td>
                    <td class="px-5 py-3">{{ $shipment->assignments->first()?->agent?->name ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', (string) $shipment->last_failure_reason) ?: '—' }}</td>
                    <td class="px-5 py-3">{{ $shipment->attempts }} / {{ $maxAttempts }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$shipment->status" />@if ($shipment->rescheduled_for)<p class="text-xs text-fg-secondary">{{ $shipment->rescheduled_for->format('j M') }}</p>@endif</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            @if ($shipment->status->value === 'failed' && $shipment->attempts < $maxAttempts)
                                <form method="POST" action="{{ route('carrier.shipments.act', [$shipment->id, 'reschedule']) }}" class="flex items-center gap-2">@csrf
                                    <input type="date" name="date" required class="h-8 rounded border border-line px-2 text-xs" min="{{ now($currentCountry->timezone)->toDateString() }}">
                                    <x-ui.button type="submit" size="sm">{{ __('Reschedule') }}</x-ui.button>
                                </form>
                            @endif
                            @if (in_array($shipment->status->value, ['failed', 'rescheduled'], true))
                                <form method="POST" action="{{ route('carrier.shipments.act', [$shipment->id, 'return']) }}" class="flex items-center gap-2">@csrf
                                    <input name="reason" required class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Reason') }}">
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Return to origin') }}</x-ui.button>
                                </form>
                            @endif
                            @if ($shipment->status->value === 'returning_to_origin')
                                <form method="POST" action="{{ route('carrier.shipments.act', [$shipment->id, 'returned']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Back at origin') }}</x-ui.button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
