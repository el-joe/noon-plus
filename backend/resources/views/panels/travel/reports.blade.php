<x-layouts.panel :title="__('Reports')" :subtitle="__('Booking requests of the last six months · payment is settled with your customers outside Nawy')" :breadcrumbs="[__('Finance'), __('Reports')]">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Requests (6 months)')" :value="$months->sum('requests')" />
        <x-ui.stat-card :label="__('Confirmed')" :value="$months->sum('confirmed')" />
        <x-ui.stat-card :label="__('Confirmation rate')" :value="$conversion === null ? '—' : $conversion.'%'" />
    </div>
    <x-ui.card :title="__('By month')" flush>
        <x-ui.table :headers="[__('Month'), __('Requests'), __('Confirmed'), __('Rejected / cancelled'), __('Travellers'), __('Confirmed value')]">
            @foreach ($months as $row)
                <tr class="border-t border-line" data-month="{{ $row['month']->format('Y-m') }}">
                    <td class="px-5 py-3">{{ $row['month']->format('M Y') }}</td>
                    <td class="px-5 py-3">{{ $row['requests'] }}</td>
                    <td class="px-5 py-3">{{ $row['confirmed'] }}</td>
                    <td class="px-5 py-3">{{ $row['rejected_or_cancelled'] }}</td>
                    <td class="px-5 py-3">{{ $row['travellers'] }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$row['value']" :currency="$currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    <x-ui.card :title="__('Top packages')">
        <ol class="flex list-decimal flex-col gap-1 ps-5 text-sm">
            @forelse ($top as $packageId => $count)
                <li>{{ $packages->get($packageId, '—') }} · {{ trans_choice(':count booking|:count bookings', $count) }}</li>
            @empty
                <li class="list-none text-fg-secondary">{{ __('No confirmed bookings yet.') }}</li>
            @endforelse
        </ol>
    </x-ui.card>
</x-layouts.panel>
