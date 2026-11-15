<x-layouts.panel :title="__('Departures & capacity')" :subtitle="__('Seat holds release after :minutes minutes', ['minutes' => $holdMinutes])" :breadcrumbs="[__('Trips'), __('Departures & capacity')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Departure'), __('Return'), __('Trip'), __('Room prices'), __('Capacity'), __('Requested / confirmed'), __('Held (not submitted)'), __('Status')]" :rows="$departures->isNotEmpty()" :empty="__('No departures yet.')">
            @foreach ($departures as $departure)
                @php($held = (int) $departure->held_seats)
                @php($left = $departure->seat_capacity - $departure->seats_reserved)
                <tr class="border-t border-line" data-departure="{{ $departure->id }}">
                    <td class="px-5 py-3 font-semibold">{{ $departure->departs_on->format('D j M') }}</td>
                    <td class="px-5 py-3">{{ $departure->returns_on->format('D j M') }}</td>
                    <td class="px-5 py-3">{{ $departure->package->title_en }}</td>
                    <td class="px-5 py-3 text-xs">{{ $departure->rooms->map(fn ($room) => ucfirst($room->room_type).' '.number_format((float) $room->price_per_person))->implode(' · ') }}</td>
                    <td class="px-5 py-3">
                        <form method="POST" action="{{ route('travel_agency.departures.resize', $departure->id) }}" class="flex gap-2">
                            @csrf
                            <label class="sr-only" for="cap-{{ $departure->id }}">{{ __('Capacity') }}</label>
                            <input id="cap-{{ $departure->id }}" name="seat_capacity" type="number" value="{{ $departure->seat_capacity }}" class="h-8 w-20 rounded-lg border border-line bg-surface px-2 text-sm">
                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button>
                        </form>
                    </td>
                    <td class="px-5 py-3">{{ $departure->seats_reserved - $held }}</td>
                    <td class="px-5 py-3">{{ $held }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$left <= 0 ? 'sold_out' : ($left <= max(2, intdiv($departure->seat_capacity, 10)) ? 'almost_full' : 'on_sale')" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    <p class="rounded-xl border border-line bg-info-subtle p-4 text-sm">{{ __('Capacity is locked per seat during checkout.') }}</p>
    {{ $departures->links() }}
</x-layouts.panel>
