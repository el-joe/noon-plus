<x-layouts.panel :title="__('Driver documents')" :subtitle="__('Status and expiry of your drivers\' documents. Drivers upload them in the delivery app; Nawy reviews them.')" :breadcrumbs="[__('Team'), __('Driver documents')]">
    <x-ui.card flush>
        <x-ui.table :headers="array_merge([__('Driver')], $requirements->map(fn ($type) => $type->name())->all())" :rows="$agents->isNotEmpty()">
            @foreach ($agents as $agent)
                <tr class="border-t border-line align-top" data-driver="{{ $agent->id }}">
                    <td class="px-5 py-3">{{ $agent->name }}<br><x-ui.status-badge :status="$agent->status" /></td>
                    @foreach ($requirements as $type)
                        @php($upload = $uploads->get($agent->id)?->get($type->id))
                        <td class="px-5 py-3">
                            @if ($upload === null)
                                <x-ui.badge tone="danger">{{ __('Missing') }}</x-ui.badge>
                            @else
                                <x-ui.status-badge :status="$upload->status" />
                                @if ($upload->valid_until)
                                    <p class="text-xs {{ $upload->valid_until->lessThanOrEqualTo(now()->addDays(30)) ? 'text-fg-danger' : 'text-fg-secondary' }}">{{ __('Valid until :date', ['date' => $upload->valid_until->toDateString()]) }}</p>
                                @endif
                                @if ($upload->rejection_reason)<p class="text-xs text-fg-secondary">{{ $upload->rejection_reason }}</p>@endif
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
