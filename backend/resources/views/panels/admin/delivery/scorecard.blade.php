@php($pct = fn ($value) => $value === null ? '—' : $value.' %')
<x-layouts.panel :title="__('Delivery scorecard')" :subtitle="__(':country · last :days days of finished attempts', ['country' => $country->name_en, 'days' => $days])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Scorecard')]">
    @include('panels.admin.delivery._nav')
    <div class="flex gap-2">
        @foreach ([7, 30, 90] as $option)
            <x-ui.button :href="route('admin.delivery.scorecard', ['days' => $option])" size="sm" :variant="$days === $option ? 'primary' : 'outline'">{{ __(':days days', ['days' => $option]) }}</x-ui.button>
        @endforeach
    </div>

    <x-ui.card :title="__('Shipping companies')" flush>
        <x-ui.table :headers="[__('Company'), __('Attempts'), __('Delivered'), __('Success'), __('On time'), __('SLA breaches'), __('Claims (upheld)'), __('Score')]" :rows="$carriers->isNotEmpty()">
            @foreach ($carriers as $row)
                <tr class="border-t border-line" data-carrier-score="{{ $row['carrier']->id }}">
                    <td class="px-5 py-3 font-medium">{{ $row['carrier']->name }}</td>
                    <td class="px-5 py-3">{{ $row['attempts'] }}</td>
                    <td class="px-5 py-3">{{ $row['delivered'] }}</td>
                    <td class="px-5 py-3">{{ $pct($row['success_rate']) }}</td>
                    <td class="px-5 py-3">{{ $pct($row['on_time_rate']) }}</td>
                    <td class="px-5 py-3">{{ $row['sla_breaches'] }}</td>
                    <td class="px-5 py-3">{{ $row['claims'] }} ({{ $row['claims_upheld'] }})</td>
                    <td class="px-5 py-3 font-semibold">{{ $row['score'] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Agents')" flush>
        <x-ui.table :headers="[__('Agent'), __('Attempts'), __('Delivered'), __('Failed'), __('Success'), __('On time'), __('Avg. minutes'), __('SLA breaches'), __('Score')]" :rows="$agents->isNotEmpty()">
            @foreach ($agents as $row)
                <tr class="border-t border-line" data-agent-score="{{ $row['agent']->id }}">
                    <td class="px-5 py-3 font-medium">{{ $row['agent']->name }}</td>
                    <td class="px-5 py-3">{{ $row['attempts'] }}</td>
                    <td class="px-5 py-3">{{ $row['delivered'] }}</td>
                    <td class="px-5 py-3">{{ $row['failed'] }}</td>
                    <td class="px-5 py-3">{{ $pct($row['success_rate']) }}</td>
                    <td class="px-5 py-3">{{ $pct($row['on_time_rate']) }}</td>
                    <td class="px-5 py-3">{{ $row['avg_minutes'] ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $row['sla_breaches'] }}</td>
                    <td class="px-5 py-3 font-semibold">{{ $row['score'] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
