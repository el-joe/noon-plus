<x-layouts.panel :title="$package ? $package->title_en : __('New package')" :subtitle="__('Itinerary, inclusions, cancellation tiers, departures and room prices')" :breadcrumbs="[__('Packages & trips'), __('Package editor')]">
    @if ($package)
        <x-slot:actions>
            <x-ui.status-badge :status="$package->status" />
            @if ($package->status->value === 'draft')
                <form method="POST" action="{{ route('travel_agency.packages.submit', $package->id) }}">@csrf<x-ui.button type="submit">{{ __('Submit for review') }}</x-ui.button></form>
            @endif
        </x-slot:actions>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $package ? route('travel_agency.packages.update', $package->id) : route('travel_agency.packages.store') }}" class="grid gap-4 lg:grid-cols-2">
        @csrf
        @if ($package) @method('PUT') @endif
        <x-ui.card :title="__('Trip')">
            <div class="grid gap-3">
                <x-ui.input :label="__('Title (English)')" name="title_en" :value="old('title_en', $package?->title_en)" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" :value="old('title_ar', $package?->title_ar)" required />
                <x-ui.select :label="__('Trip type')" name="trip_type" :selected="old('trip_type', $package?->trip_type ?? 'holiday')" :options="['umrah' => __('Umrah'), 'hajj' => __('Hajj'), 'holiday' => __('Holiday'), 'honeymoon' => __('Honeymoon'), 'adventure' => __('Adventure'), 'business' => __('Business')]" />
                <x-ui.input :label="__('Destination city')" name="destination_city" :value="old('destination_city', $package?->destination_city)" required />
                <x-ui.input :label="__('Destination country')" name="destination_country" :value="old('destination_country', $package?->destination_country)" />
                <x-ui.input :label="__('Nights')" name="nights" type="number" :value="old('nights', $package?->nights ?? 3)" required />
                <x-ui.textarea :label="__('Description (English)')" name="description_en" :value="old('description_en', $package?->description_en)" />
                <x-ui.textarea :label="__('Description (Arabic)')" name="description_ar" :value="old('description_ar', $package?->description_ar)" />
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Itinerary & policy')">
            <div class="grid gap-3">
                <x-ui.textarea :label="__('Itinerary — one day per line')" name="itinerary_text" :rows="5" :value="old('itinerary_text', collect($package?->itinerary ?? [])->pluck('title')->implode(PHP_EOL))" />
                <x-ui.textarea :label="__('Inclusions — one per line')" name="inclusions_text" :value="old('inclusions_text', implode(PHP_EOL, $package?->inclusions ?? []))" />
                <x-ui.textarea :label="__('Exclusions — one per line')" name="exclusions_text" :value="old('exclusions_text', implode(PHP_EOL, $package?->exclusions ?? []))" />
                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">{{ __('Cancellation policy shown to customers (refund % if cancelled at least N days before; refunds are settled by the agency)') }}</legend>
                    @foreach (range(0, 2) as $i)
                        @php($tier = ($package?->cancellation_policy ?? [])[$i] ?? null)
                        <div class="grid grid-cols-2 gap-2">
                            <x-ui.input :label="__('Days before')" :name="'policy['.$i.'][days_before]'" type="number" :value="$tier['days_before'] ?? null" />
                            <x-ui.input :label="__('Refund %')" :name="'policy['.$i.'][refund_percent]'" type="number" :value="$tier['refund_percent'] ?? null" />
                        </div>
                    @endforeach
                </fieldset>
                @if ($package)
                    <label class="text-sm font-medium" for="contract">{{ __('Trip contract file (PDF/DOC)') }} @if ($package->contract_path)<span class="text-fg-success">· {{ __('on file') }}</span>@endif</label>
                    <input id="contract" type="file" name="contract" accept=".pdf,.doc,.docx" class="text-sm">
                @endif
            </div>
        </x-ui.card>
        <div class="lg:col-span-2"><x-ui.button type="submit">{{ __('Save package') }}</x-ui.button></div>
    </form>

    @if ($package)
        <x-ui.card :title="__('Departures & room prices')" flush>
            <x-ui.table :headers="[__('Departure'), __('Return'), __('Room prices'), __('Capacity'), __('Reserved')]" :rows="$package->departures->isNotEmpty()" :empty="__('No departures yet.')">
                @foreach ($package->departures as $departure)
                    <tr class="border-t border-line">
                        <td class="px-5 py-3">{{ $departure->departs_on->format('D j M Y') }}</td>
                        <td class="px-5 py-3">{{ $departure->returns_on->format('D j M') }}</td>
                        <td class="px-5 py-3 text-xs">{{ $departure->rooms->map(fn ($room) => ucfirst($room->room_type).' '.number_format((float) $room->price_per_person, 2))->implode(' · ') }}</td>
                        <td class="px-5 py-3">{{ $departure->seat_capacity }}</td>
                        <td class="px-5 py-3">{{ $departure->seats_reserved }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <form method="POST" action="{{ route('travel_agency.packages.departures.store', $package->id) }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Departs on')" name="departs_on" type="date" required />
                <x-ui.input :label="__('Returns on')" name="returns_on" type="date" required />
                <x-ui.input :label="__('Seats')" name="seat_capacity" type="number" required />
                @foreach ($roomTypes as $type)
                    <x-ui.input :label="__(':type price per person', ['type' => ucfirst($type)])" :name="'rooms['.$type.']'" type="number" step="any" min="0" />
                @endforeach
                <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Add departure') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif
</x-layouts.panel>
