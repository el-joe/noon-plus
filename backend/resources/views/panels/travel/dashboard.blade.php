<x-layouts.panel :title="__('Dashboard')" :subtitle="$agency->store_name" :breadcrumbs="[__('Travel Partner'), __('Dashboard')]">
    @if (app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'travel_agency', 'packages', true))
        <x-slot:actions>
            <x-ui.button :href="route('travel_agency.packages.create')">{{ __('New package') }}</x-ui.button>
        </x-slot:actions>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.stat-card :label="__('Live packages')" :value="$stats['live_packages']" />
        <x-ui.stat-card :label="__('Requests to answer')" :value="$stats['awaiting_decision']" />
        <x-ui.stat-card :label="__('Confirmed bookings')" :value="$stats['confirmed']" />
        <x-ui.stat-card :label="__('Confirmed value (paid to you directly)')" :value="number_format((float) $stats['revenue'], 2).' '.$currency" />
        <x-ui.stat-card :label="__('New inquiries')" :value="$stats['new_inquiries']" />
    </div>

    <x-ui.card :title="__('Upcoming departures')" flush>
        <x-ui.table :headers="[__('Trip'), __('Departure'), __('Seats left')]" :rows="$upcoming->isNotEmpty()" :empty="__('No upcoming departures.')">
            @foreach ($upcoming as $departure)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $departure->package->title_en }}</td>
                    <td class="px-5 py-3">{{ $departure->departs_on->format('D j M') }}</td>
                    <td class="px-5 py-3">{{ $departure->seat_capacity - $departure->seats_reserved }} / {{ $departure->seat_capacity }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
