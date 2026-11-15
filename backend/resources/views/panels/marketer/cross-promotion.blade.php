<x-layouts.panel :title="__('Cross-promotion')" :subtitle="__('Promote fellow creators and keep up with this month’s obligations')" :breadcrumbs="[__('Creator Studio'), __('Cross-promotion')]">
    @unless ($enabled)
        <div role="status" class="rounded-lg border border-line bg-subtle px-4 py-3 text-sm">{{ __('Cross-promotion obligations are not active in your country yet.') }}</div>
    @endunless

    <x-ui.card :title="__('This month')" flush>
        <x-ui.table :headers="[__('Policy'), __('Required'), __('Done'), __('Status')]" :rows="$rows->isNotEmpty()" :empty="__('No policies.')">
            @foreach ($rows as $row)
                <tr class="border-t border-line" data-policy="{{ $row['policy']->id }}">
                    <td class="px-5 py-3">{{ $row['policy']->name }}@unless ($row['eligible'])<div class="text-xs text-fg-secondary">{{ __('Not eligible yet') }}</div>@endunless</td>
                    <td class="px-5 py-3">{{ $row['required'] }}</td>
                    <td class="px-5 py-3">{{ $row['completed'] }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$row['status']" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if (in_array('influencer', $marketer->job_types))
        <x-ui.card :title="__('Promote a peer')" :subtitle="__('Another influencer’s own Fulfilled-by-Nawy listing · one promotion per creator per month')">
            <form method="POST" action="{{ route('marketer.cross-promotion.peer') }}" class="flex items-end gap-3">
                @csrf
                <x-ui.input :label="__('Listing number')" name="listing_id" type="number" required />
                <x-ui.button type="submit">{{ __('Start promotion') }}</x-ui.button>
            </form>
            @error('listing_id')<p class="mt-2 text-xs text-fg-danger">{{ $message }}</p>@enderror
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Peer promotions')" flush>
        <x-ui.table :headers="[__('Campaign'), __('Product'), __('Role'), __('Status')]" :rows="$peerCampaigns->isNotEmpty()">
            @foreach ($peerCampaigns as $campaign)
                <tr class="border-t border-line"><td class="px-5 py-3">{{ $campaign->title }}</td><td class="px-5 py-3 text-xs">{{ $campaign->listing->variant->product->title_en }}</td><td class="px-5 py-3 text-xs">{{ $campaign->requested_by_marketer_id === $marketer->id ? __('Promoter') : __('Promoted by others') }}</td><td class="px-5 py-3"><x-ui.status-badge :status="$campaign->status" /></td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
