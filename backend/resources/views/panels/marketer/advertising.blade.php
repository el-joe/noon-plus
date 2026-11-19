<x-layouts.panel :title="__('Advertising')" :subtitle="__('Ad packages, paid placements and coupon participation — paid from your wallet')" :breadcrumbs="[__('Campaigns'), __('Advertising')]">
    {{-- QC-010: the marketer's advertising channel. --}}
    <x-ui.card :title="__('Ad packages')" flush>
        <x-ui.table :headers="[__('Package'), __('Price'), __('Duration'), __('Subscribe')]" :rows="$packages->isNotEmpty()" :empty="__('No packages on sale.')">
            @foreach ($packages as $package)
                <tr class="border-t border-line" data-package="{{ $package->id }}">
                    <td class="px-5 py-3">{{ app()->getLocale() === 'ar' ? $package->name_ar : $package->name_en }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$package->price" :currency="$currency" /></td>
                    <td class="px-5 py-3">{{ trans_choice(':count day|:count days', $package->duration_days, ['count' => $package->duration_days]) }}</td>
                    <td class="px-5 py-3">
                        <form method="POST" enctype="multipart/form-data" action="{{ route('marketer.advertising.packages.subscribe', $package->id) }}" class="flex flex-wrap gap-2">
                            @csrf
                            <select name="payment_method" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm" aria-label="{{ __('Payment') }}"><option value="wallet">{{ __('Wallet') }}</option><option value="bank_transfer">{{ __('Bank transfer') }}</option></select>
                            <input type="file" name="proof" class="text-xs" aria-label="{{ __('Transfer proof') }}">
                            <x-ui.button type="submit" size="sm">{{ __('Subscribe') }}</x-ui.button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($subscriptions->isNotEmpty())
            <ul class="border-t border-line p-5 text-sm">
                @foreach ($subscriptions as $subscription)
                    <li class="flex justify-between py-1"><span>{{ $subscription->package?->name_en }}</span><x-ui.status-badge :status="$subscription->status" /></li>
                @endforeach
            </ul>
        @endif
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
        <form method="POST" enctype="multipart/form-data" action="{{ route('marketer.advertising.bookings.store') }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4">
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
                            <form method="POST" enctype="multipart/form-data" action="{{ route('marketer.advertising.coupons.join', $invitation->id) }}" class="flex flex-wrap gap-2">
                                @csrf
                                <label class="sr-only" for="fee-{{ $invitation->id }}">{{ __('Offered fee') }}</label>
                                <input id="fee-{{ $invitation->id }}" name="offered_fee" type="number" step="0.01" value="{{ $invitation->min_fee }}" class="h-8 w-24 rounded-lg border border-line bg-surface px-2 text-sm">
                                <select name="payment_method" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm" aria-label="{{ __('Payment') }}"><option value="wallet">{{ __('Wallet') }}</option><option value="bank_transfer">{{ __('Bank transfer') }}</option></select>
                                <input type="file" name="proof" class="text-xs" aria-label="{{ __('Transfer proof') }}">
                                <x-ui.button type="submit" size="sm">{{ __('Request to join') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($requests->isNotEmpty())
        <x-ui.card :title="__('My coupon participation requests')" flush>
            <ul class="p-5 text-sm">
                @foreach ($requests as $participation)
                    <li class="flex justify-between py-1"><span>#{{ $participation->coupon_participation_invitation_id }} · <x-ui.money :amount="$participation->offered_fee" :currency="$participation->invitation?->currency ?? ''" /></span><x-ui.status-badge :status="$participation->status" /></li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</x-layouts.panel>
