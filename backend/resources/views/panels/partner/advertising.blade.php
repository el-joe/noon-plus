<x-layouts.panel :title="__('Advertising')" :subtitle="__('Sponsored products, paid placements and coupon participation — charged on your statement')" :breadcrumbs="[__('Growth'), __('Advertising')]">
    <x-ui.card :title="__('Sponsored product campaigns')" flush>
        <x-ui.table :headers="[__('Campaign'), __('Model'), __('Bid'), __('Daily budget'), __('Spent today'), __('Clicks / impressions'), __('Status'), '']" :rows="$campaigns->isNotEmpty()" :empty="__('No campaigns yet.')">
            @foreach ($campaigns as $campaign)
                <tr class="border-t border-line" data-campaign="{{ $campaign->id }}">
                    <td class="px-5 py-3">{{ $campaign->name }}<span class="block text-xs text-fg-secondary">{{ $campaign->number }}</span></td>
                    <td class="px-5 py-3 uppercase">{{ $campaign->pricing_model }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$campaign->bid" :currency="$campaign->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$campaign->daily_budget" :currency="$campaign->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="$campaign->spent_today" :currency="$campaign->currency" /></td>
                    <td class="px-5 py-3">{{ $campaign->clicks }} / {{ $campaign->impressions }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$campaign->status" /></td>
                    <td class="px-5 py-3">
                        @foreach (in_array($campaign->status, \App\Domains\Advertising\Http\Controllers\Seller\SellerAdvertisingController::SELLER_MOVABLE, true) ? array_intersect(['active', 'paused'], array_map(fn ($status) => $status->value, $campaign->status->allowedNext())) : [] as $next)
                            <form method="POST" action="{{ route('partner.advertising.campaigns.move', $campaign->id) }}" class="inline">@csrf<input type="hidden" name="status" value="{{ $next }}"><x-ui.button type="submit" size="sm" variant="outline">{{ $next === 'active' ? __('Resume') : __('Pause') }}</x-ui.button></form>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <form method="POST" action="{{ route('partner.advertising.campaigns.store') }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4">
            @csrf
            <x-ui.select :label="__('Listing')" name="listing_id" :options="$listings->mapWithKeys(fn ($listing) => [$listing->id => $listing->variant->product->title()])->all()" />
            <x-ui.select :label="__('Pricing')" name="pricing_model" selected="cpc" :options="['cpc' => __('Cost per click'), 'cpm' => __('Cost per 1,000 views')]" />
            <x-ui.input :label="__('Bid')" name="bid" type="number" step="any" min="0" required />
            <x-ui.input :label="__('Daily budget')" name="daily_budget" type="number" step="any" min="0" required />
            <x-ui.input :label="__('Total budget (optional)')" name="total_budget" type="number" step="any" min="0" />
            <x-ui.input :label="__('Keywords (comma separated, optional)')" name="keywords" />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Create campaign') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Paid placements')" flush>
        <x-ui.table :headers="[__('Booking'), __('Placement'), __('Dates'), __('Amount'), __('Status')]" :rows="$bookings->isNotEmpty()" :empty="__('No bookings yet.')">
            @foreach ($bookings as $booking)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $booking->number }}</td>
                    <td class="px-5 py-3">{{ $booking->slot->name }}</td>
                    <td class="px-5 py-3">{{ $booking->starts_on->format('j M') }} – {{ $booking->ends_on->format('j M') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$booking->amount" :currency="$booking->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$booking->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
        <form method="POST" enctype="multipart/form-data" action="{{ route('partner.advertising.bookings.store') }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4">
            @csrf
            <x-ui.select :label="__('Placement')" name="ad_slot_id" :options="$slots->mapWithKeys(fn ($slot) => [$slot->id => $slot->name.' · '.number_format((float) $slot->price_per_day, 2).'/'.__('day')])->all()" />
            <x-ui.input :label="__('From')" name="starts_on" type="date" required />
            <x-ui.input :label="__('To')" name="ends_on" type="date" required />
            <x-ui.input :label="__('Link')" name="link_url" type="url" />
            <x-ui.input :label="__('Headline')" name="headline" />
            <div><label class="text-sm font-medium" for="creative">{{ __('Image creative') }}</label><input id="creative" type="file" name="creative" accept="image/*" required class="text-sm"></div>
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Book & pay') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    @if ($invitations->isNotEmpty())
        <x-ui.card :title="__('Coupon participation invitations')" flush>
            <x-ui.table :headers="[__('Invitation'), __('Coupon'), __('Minimum fee'), __('Closes'), __('Join')]">
                @foreach ($invitations as $invitation)
                    <tr class="border-t border-line">
                        <td class="px-5 py-3">{{ $invitation->title }}</td>
                        <td class="px-5 py-3">{{ $invitation->coupon->code }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="$invitation->min_fee" :currency="$invitation->currency" /></td>
                        <td class="px-5 py-3">{{ $invitation->closes_at->format('j M H:i') }}</td>
                        <td class="px-5 py-3">
                            <form method="POST" enctype="multipart/form-data" action="{{ route('partner.advertising.coupons.join', $invitation->id) }}" class="flex flex-wrap gap-2">
                                @csrf
                                <label class="sr-only" for="fee-{{ $invitation->id }}">{{ __('Offered fee') }}</label>
                                <input id="fee-{{ $invitation->id }}" name="offered_fee" type="number" step="0.01" value="{{ rtrim(rtrim((string) $invitation->min_fee, '0'), '.') }}" class="h-8 w-24 rounded-lg border border-line bg-surface px-2 text-sm">
                                <select name="payment_method" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm" aria-label="{{ __('Payment') }}"><option value="wallet">{{ __('Statement') }}</option><option value="bank_transfer">{{ __('Bank transfer') }}</option></select>
                                <input type="file" name="proof" class="text-xs" aria-label="{{ __('Transfer proof') }}">
                                <x-ui.button type="submit" size="sm">{{ __('Request to join') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.panel>
