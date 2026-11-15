<x-layouts.panel :title="__('Special requests')" :subtitle="__('Start a request to make the customer your lead — the first broker wins')" :breadcrumbs="[__('Leads'), __('Special requests')]">
    <x-ui.card :title="__('Open requests')" flush>
        <x-ui.table :headers="[__('Request'), __('Looking for'), __('City'), __('Budget'), '']" :rows="$open->isNotEmpty()" :empty="__('No open requests.')">
            @foreach ($open as $special)
                <tr class="border-t border-line align-top" data-request="{{ $special->id }}">
                    <td class="px-5 py-3">{{ $special->number }}<span class="block text-xs text-fg-secondary">{{ $special->created_at?->diffForHumans() }}</span></td>
                    <td class="px-5 py-3 text-sm">{{ trim(($special->bedrooms ? $special->bedrooms.' BR ' : '').($special->property_type ?? '').' · '.($special->purpose ?? '')) }}<span class="block text-xs text-fg-secondary">{{ \Illuminate\Support\Str::limit($special->details, 120) }}</span></td>
                    <td class="px-5 py-3">{{ $special->city }}</td>
                    <td class="px-5 py-3">{{ $special->budget_max ? number_format((float) $special->budget_max) : '—' }}</td>
                    <td class="px-5 py-3"><form method="POST" action="{{ route('marketer.broker.special-requests.claim', $special->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Start') }}</x-ui.button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('My requests')" flush>
        <x-ui.table :headers="[__('Request'), __('Status'), '']" :rows="$mine->isNotEmpty()" :empty="__('You have not started any request yet.')">
            @foreach ($mine as $special)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $special->number }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$special->status" /></td>
                    <td class="px-5 py-3">
                        @if ($special->status->value === 'in_progress')
                            @foreach (['fulfilled' => __('Fulfilled'), 'closed' => __('Close')] as $value => $label)
                                <form method="POST" action="{{ route('marketer.broker.special-requests.finish', $special->id) }}" class="inline">@csrf<input type="hidden" name="status" value="{{ $value }}"><x-ui.button type="submit" size="sm" variant="outline">{{ $label }}</x-ui.button></form>
                            @endforeach
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
