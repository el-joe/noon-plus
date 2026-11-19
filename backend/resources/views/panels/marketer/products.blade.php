<x-layouts.panel :title="__('My products')" :subtitle="__('Your picks on your creator page and referral pages · :count of :max', ['count' => $rows->count(), 'max' => $max])" :breadcrumbs="[__('Creator Studio'), __('My products')]">
    <x-slot:actions>
        <x-ui.button variant="outline" data-copy-text="{{ $creatorUrl }}" data-copied-label="{{ __('Copied') }}">{{ __('Copy my page link') }}</x-ui.button>
    </x-slot:actions>

    @error('listing_id')
        <div role="alert" class="rounded-lg border border-line bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>
    @enderror

    <x-ui.card :title="__('Add from the catalogue')" :subtitle="__('Search active products in :country by name or product id.', ['country' => $country->name_en])">
        <form method="GET" action="{{ route('marketer.products') }}" class="flex flex-wrap items-end gap-3" role="search">
            <x-ui.input :label="__('Search')" name="q" :value="$search" class="min-w-64" />
            <x-ui.button type="submit" variant="outline">{{ __('Search') }}</x-ui.button>
        </form>
        @if ($search !== '')
            <div class="mt-4 overflow-hidden rounded-lg border border-line">
                <x-ui.table :headers="[__('Product'), __('Seller'), __('Price'), '']" :rows="$results->isNotEmpty()" :empty="__('No active product matches.')">
                    @foreach ($results as $listing)
                        <tr class="border-t border-line" data-result="{{ $listing->id }}">
                            <td class="px-5 py-3">{{ $listing->variant->product->title() }}</td>
                            <td class="px-5 py-3 text-xs">{{ $listing->vendor?->store_name }}</td>
                            <td class="px-5 py-3"><x-ui.money :amount="$listing->effectivePrice()" :currency="$country->currency" /></td>
                            <td class="px-5 py-3 text-end">
                                <form method="POST" action="{{ route('marketer.products.store') }}">
                                    @csrf
                                    <input type="hidden" name="listing_id" value="{{ $listing->id }}">
                                    <x-ui.button type="submit" size="sm">{{ __('Add to my picks') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('My picks')" :subtitle="__('Shown in this order. Clicks and orders are counted for picks you promote in a live campaign.')" flush>
        <x-ui.table :headers="[__('#'), __('Product'), __('Link or code'), __('Clicks'), __('Orders'), '']" :rows="$rows->isNotEmpty()" :empty="__('No picks yet. Search the catalogue to add products.')">
            @foreach ($rows as $row)
                @php($pick = $row['pick'])
                <tr class="border-t border-line" data-pick="{{ $pick->listing_id }}">
                    <td class="px-5 py-3 text-fg-secondary">{{ $loop->iteration }}</td>
                    <td class="px-5 py-3">
                        {{ $pick->listing?->variant?->product?->title() ?? __('Removed product') }}
                        <span class="block text-xs text-fg-secondary">{{ $pick->listing?->vendor?->store_name }}@if ($pick->listing && $pick->listing->status !== \App\Domains\Listings\Enums\ListingStatus::Active) · {{ __('Not on sale: hidden from your page') }}@endif</span>
                    </td>
                    <td class="px-5 py-3">
                        @if ($row['share'])
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm">{{ $row['share']['code'] }}</span>
                                <x-ui.button size="sm" variant="outline" data-copy-text="{{ $row['share']['url'] }}" data-copied-label="{{ __('Copied') }}">{{ __('Copy link') }}</x-ui.button>
                            </div>
                            <span class="block text-xs text-fg-secondary">{{ $row['invitation']->campaign->title }}</span>
                        @else
                            <span class="text-xs text-fg-secondary">{{ __('No campaign: shared through your creator page') }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-3">{{ $row['clicks'] ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $row['orders'] ?? '—' }}</td>
                    <td class="px-5 py-3">
                        <div class="flex justify-end gap-1">
                            @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                <form method="POST" action="{{ route('marketer.products.move', $pick->id) }}">
                                    @csrf
                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                    <x-ui.button type="submit" size="sm" variant="ghost" :disabled="($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last)" aria-label="{{ $direction === 'up' ? __('Move up') : __('Move down') }}">{{ $arrow }}</x-ui.button>
                                </form>
                            @endforeach
                            <form method="POST" action="{{ route('marketer.products.destroy', $pick->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
