<x-layouts.panel :title="__('Marketer campaigns')" :subtitle="__('Review queue, live campaigns and platform campaigns')" :breadcrumbs="[__('Growth'), __('Marketer campaigns')]">
    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :selected="request('status')" :placeholder="__('All statuses')" onchange="this.form.submit()" />
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Owner'), __('Listing'), __('Budget'), __('Fee'), __('Status')]" :rows="$campaigns->isNotEmpty()">
            @foreach ($campaigns as $campaign)
                <tr class="border-t border-line" data-campaign="{{ $campaign->id }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.marketers.campaigns.show', $campaign->id) }}">{{ $campaign->title }}</a><div class="text-xs text-fg-secondary">{{ $campaign->number }} · {{ $campaign->origin->letter() }}</div></td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->vendor?->store_name ?? __('Platform') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->listing->variant->product->title_en }}</td>
                    <td class="px-5 py-3">@if ($campaign->budget === null) {{ __('Unlimited') }} @else <x-ui.money :amount="$campaign->budget" :currency="$campaign->currency" /> @endif</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$campaign->fee_amount" :currency="$campaign->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$campaign->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $campaigns->links() }}

    @can('marketer_campaigns.manage')
        <x-ui.card :title="__('New platform campaign (origin C)')">
            <form method="POST" action="{{ route('admin.marketers.campaigns.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('Title')" name="title" required />
                <x-ui.select :label="__('Listing')" name="listing_id" :options="$listings->mapWithKeys(fn ($l) => [$l->id => '#'.$l->id.' '.$l->variant->product->title_en])->all()" :placeholder="__('Choose')" />
                <x-ui.input :label="__('Budget (blank = unlimited)')" name="budget" type="number" step="0.001" min="0" />
                <x-ui.datetime-input :label="__('Ends')" name="ends_at" :country="$currentCountry" />
                <x-ui.select :label="__('Influencer')" name="influencer_ids[]" :options="$influencers->pluck('display_name', 'id')->all()" :placeholder="__('None')" />
                <x-ui.checkbox :label="__('Open to affiliates and brokers')" name="open_to_affiliates" />
                @foreach (['listing_id', 'budget', 'ends_at', 'influencer_ids'] as $field)@error($field)<p class="text-xs text-fg-danger sm:col-span-3">{{ $message }}</p>@enderror @endforeach
                <div><x-ui.button type="submit">{{ __('Create') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
