<x-layouts.panel :title="__('Marketer campaigns')" :subtitle="__('Influencers, affiliates and brokers promoting your listings')" :breadcrumbs="[__('Seller Center'), __('Growth'), __('Marketer campaigns')]">
    <x-slot:actions>
        <x-ui.button :href="route('partner.campaigns.create')">{{ __('New marketer campaign') }}</x-ui.button>
    </x-slot:actions>

    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :selected="request('status')" :placeholder="__('All statuses')" onchange="this.form.submit()" />
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Origin'), __('Listing'), __('Budget'), __('Fee'), __('Status')]" :rows="$campaigns->isNotEmpty()" :empty="__('No campaigns yet. Launch one to get influencers selling for you.')">
            @foreach ($campaigns as $campaign)
                <tr class="border-t border-line" data-campaign="{{ $campaign->id }}">
                    <td class="px-5 py-3"><a class="font-medium text-fg-link" href="{{ route('partner.campaigns.show', $campaign->id) }}">{{ $campaign->title }}</a><div class="text-xs text-fg-secondary">{{ $campaign->number }}</div></td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->origin->letter() }} · {{ str_replace('_', ' ', $campaign->origin->value) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->listing->variant->product->title_en }}</td>
                    <td class="px-5 py-3">@if ($campaign->budget === null) {{ __('Unlimited') }} @else <x-ui.money :amount="$campaign->budget" :currency="$campaign->currency" /> @endif</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$campaign->fee_amount" :currency="$campaign->currency" /> <span class="text-xs text-fg-secondary">{{ $campaign->fee_status->value }}</span></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$campaign->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $campaigns->links() }}
</x-layouts.panel>
