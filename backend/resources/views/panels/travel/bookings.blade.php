<x-layouts.panel :title="__('Bookings')" :subtitle="__('Booking requests — no payment on Nawy: confirm, then settle payment with the customer directly')" :breadcrumbs="[__('Bookings'), __('Bookings & documents')]">
    <nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Booking status') }}">
        @foreach (['' => __('All'), 'requested' => __('Requests'), 'confirmed' => __('Confirmed'), 'completed' => __('Completed'), 'rejected' => __('Rejected'), 'cancelled' => __('Cancelled')] as $key => $label)
            <a href="{{ route('travel_agency.bookings', $key === '' ? [] : ['status' => $key]) }}" @class(['pb-2', 'border-b-2 border-brand font-semibold' => ($status ?? '') === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Booking'), __('Customer'), __('Trip'), __('Departure'), __('Seats'), __('Quoted total'), __('Status'), __('Action')]" :rows="$bookings->isNotEmpty()" :empty="__('No bookings.')">
            @foreach ($bookings as $booking)
                <tr class="border-t border-line align-top" data-booking="{{ $booking->id }}">
                    <td class="px-5 py-3 font-medium">{{ $booking->number }}@if ($booking->confirmation_number)<span class="block text-xs text-fg-secondary">{{ $booking->confirmation_number }}</span>@endif</td>
                    <td class="px-5 py-3 text-sm">{{ $booking->contact_name }}<span class="block text-xs text-fg-secondary">{{ $booking->contact_phone }} · {{ $booking->contact_email }}</span>
                        @if ($booking->travellers->isNotEmpty())<span class="block text-xs">{{ $booking->travellers->pluck('full_name')->implode(', ') }}</span>@endif
                        @if ($booking->message)<span class="block text-xs italic">“{{ $booking->message }}”</span>@endif
                    </td>
                    <td class="px-5 py-3">{{ $booking->package->title_en }}</td>
                    <td class="px-5 py-3">{{ $booking->departure->departs_on->format('j M Y') }}</td>
                    <td class="px-5 py-3">{{ $booking->seats }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$booking->total" :currency="$booking->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$booking->status" /></td>
                    <td class="px-5 py-3">
                        @if ($booking->status->value === 'requested')
                            <form method="POST" action="{{ route('travel_agency.bookings.confirm', $booking->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Confirm') }}</x-ui.button></form>
                        @endif
                        @if (in_array($booking->status->value, ['requested', 'confirmed'], true))
                            @php($stop = $booking->status->value === 'requested' ? 'reject' : 'cancel')
                            <form method="POST" action="{{ route('travel_agency.bookings.'.$stop, $booking->id) }}" class="mt-2 flex gap-2">
                                @csrf
                                <label class="sr-only" for="reason-{{ $booking->id }}">{{ __('Reason') }}</label>
                                <input id="reason-{{ $booking->id }}" name="reason" required minlength="5" placeholder="{{ __('Reason (sent to the customer)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ $stop === 'reject' ? __('Reject') : __('Cancel') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $bookings->links() }}

    @if ($modifications->isNotEmpty())
        <x-ui.card :title="__('Change requests')" flush>
            <x-ui.table :headers="[__('Booking'), __('Change'), __('Details'), __('Decision')]">
                @foreach ($modifications as $modification)
                    <tr class="border-t border-line">
                        <td class="px-5 py-3">{{ $modification->booking->number }}</td>
                        <td class="px-5 py-3">{{ str_replace('_', ' ', $modification->type) }}</td>
                        <td class="px-5 py-3 text-xs">{{ json_encode($modification->details) }}</td>
                        <td class="px-5 py-3 flex gap-2">
                            @foreach (['approve' => __('Approve'), 'reject' => __('Reject')] as $decision => $label)
                                <form method="POST" action="{{ route('travel_agency.modifications.decide', $modification->id) }}">@csrf<input type="hidden" name="decision" value="{{ $decision }}"><x-ui.button type="submit" size="sm" :variant="$decision === 'approve' ? 'primary' : 'outline'">{{ $label }}</x-ui.button></form>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.panel>
