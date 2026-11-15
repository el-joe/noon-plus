<x-layouts.panel :title="__('Active campaigns')" :breadcrumbs="[__('Creator Studio'), __('Campaigns')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Product'), __('Code'), __('Status')]" :rows="$invitations->isNotEmpty()" :empty="__('Accept an invitation or join an open campaign.')">
            @foreach ($invitations as $invitation)
                <tr class="border-t border-line">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('marketer.campaigns.show', $invitation->id) }}">{{ $invitation->campaign->title }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->campaign->listing->variant->product->title_en }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $invitation->referral_code }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invitation->campaign->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Request a campaign')" :subtitle="__('Ask a store to let you promote one of its listings (listing number from the product page)')">
        <form method="POST" action="{{ route('marketer.campaigns.request') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf
            <x-ui.input :label="__('Listing number')" name="listing_id" type="number" required />
            <x-ui.input :label="__('Title')" name="title" required />
            <x-ui.input :label="__('Note to the store')" name="notes" />
            @error('listing_id')<p class="text-xs text-fg-danger sm:col-span-3">{{ $message }}</p>@enderror
            @error('marketer')<p class="text-xs text-fg-danger sm:col-span-3">{{ $message }}</p>@enderror
            <div><x-ui.button type="submit">{{ __('Send request') }}</x-ui.button></div>
        </form>
        @if ($requests->isNotEmpty())
            <ul class="mt-4 flex flex-col gap-1 text-sm">
                @foreach ($requests as $request)<li>{{ $request->title }} — <x-ui.status-badge :status="$request->status" /></li>@endforeach
            </ul>
        @endif
    </x-ui.card>
</x-layouts.panel>
