<x-layouts.panel :title="__('Flash sales')" :subtitle="__('Offer deal prices to upcoming events · times in :zone', ['zone' => $country->timezone])" :breadcrumbs="[__('Growth'), __('Flash sales')]">
    @forelse ($openEvents as $event)
        <x-ui.card :title="$event->display_name ?? $event->name"
                   :subtitle="__('Runs :start → :end · submissions close :close · max :max per customer', [
                       'start' => $clock->inCountry($country, $event->starts_at)->format('j M H:i'),
                       'end' => $clock->inCountry($country, $event->ends_at)->format('j M H:i'),
                       'close' => $clock->inCountry($country, $event->submissionDeadline())->format('j M H:i'),
                       'max' => $event->max_qty_per_customer,
                   ])">
            <form method="POST" action="{{ route('partner.flash-sales.submit', $event->id) }}" class="grid gap-4 sm:grid-cols-4" data-event="{{ $event->id }}">
                @csrf
                <x-ui.select :label="__('Listing')" name="listing_id" :placeholder="__('Choose an active listing')"
                             :options="$listings->mapWithKeys(fn ($listing) => [$listing->id => $listing->variant->product->title().' · '.$country->currency.' '.$listing->effectivePrice().' · '.(int) $listing->available_quantity.' '.__('in stock')])->all()" />
                <x-ui.input :label="__('Flash price')" name="flash_price" inputmode="decimal" required />
                <x-ui.input :label="__('Units at this price')" name="quantity_cap" type="number" min="1" required />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Submit offer') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @empty
        <x-ui.card><p class="text-sm text-fg-secondary">{{ __('No flash sale is open for submissions right now.') }}</p></x-ui.card>
    @endforelse

    <x-ui.card :title="__('My offers')" flush>
        <x-ui.table :headers="[__('Event'), __('Listing'), __('Flash price'), __('Units'), __('Status'), '']" :rows="$submissions->isNotEmpty()" :empty="__('You have not offered anything yet.')">
            @foreach ($submissions as $submission)
                <tr class="border-t border-line" data-submission="{{ $submission->id }}">
                    <td class="px-5 py-3">{{ $submission->event->name }}</td>
                    <td class="px-5 py-3">{{ $submission->listing->variant->product->title() }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$submission->flash_price" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">{{ $submission->sold_quantity }} / {{ $submission->quantity_cap }}</td>
                    <td class="px-5 py-3">
                        <x-ui.status-badge :status="$submission->status" />
                        @if ($submission->rejection_reason)
                            <span class="block text-xs text-fg-danger">{{ $submission->rejection_reason }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-end">
                        @if ($submission->status->canMoveTo(\App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus::Withdrawn))
                            <form method="POST" action="{{ route('partner.flash-sales.withdraw', $submission->id) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Withdraw') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
