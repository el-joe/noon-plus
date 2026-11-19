<x-layouts.panel :title="__('Ads review')" :subtitle="__('Sponsored campaigns, slot bookings (creative review) and bank-transfer ad packages')" :breadcrumbs="[__('Growth'), __('Ads review')]">
    <x-ui.card :title="__('Sponsored product campaigns')" flush>
        <x-ui.table :headers="[__('Campaign'), __('Product'), __('Model / bid'), __('Budget'), __('Targeting'), __('Decision')]" :rows="$campaigns->isNotEmpty()" :empty="__('Nothing waiting.')">
            @foreach ($campaigns as $campaign)
                <tr class="border-t border-line align-top" data-campaign="{{ $campaign->id }}">
                    <td class="px-5 py-3">{{ $campaign->name }}<span class="block text-xs text-fg-secondary">{{ $campaign->number }}</span></td>
                    <td class="px-5 py-3">{{ $campaign->listing->variant->product->title() }}</td>
                    <td class="px-5 py-3 uppercase">{{ $campaign->pricing_model }} · {{ number_format((float) $campaign->bid, 2) }}</td>
                    <td class="px-5 py-3">{{ number_format((float) $campaign->daily_budget, 2) }} / {{ __('day') }}</td>
                    <td class="px-5 py-3 text-xs">{{ implode(', ', $campaign->keywords ?? []) ?: __('Automatic') }}</td>
                    <td class="px-5 py-3">@include('panels.admin.marketing._decision', ['action' => route('admin.marketing.campaigns.decide', $campaign->id), 'id' => 'c'.$campaign->id])</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Ad slot bookings')" flush>
        <x-ui.table :headers="[__('Booking'), __('Placement'), __('Dates'), __('Creative'), __('Amount'), __('Decision')]" :rows="$bookings->isNotEmpty()" :empty="__('Nothing waiting.')">
            @foreach ($bookings as $booking)
                <tr class="border-t border-line align-top" data-booking="{{ $booking->id }}">
                    <td class="px-5 py-3">{{ $booking->number }}<span class="block text-xs text-fg-secondary">{{ $booking->advertiser_type }} #{{ $booking->advertiser_id }}</span></td>
                    <td class="px-5 py-3">{{ $booking->slot->name }}</td>
                    <td class="px-5 py-3">{{ $booking->starts_on->format('j M') }} – {{ $booking->ends_on->format('j M') }}</td>
                    <td class="px-5 py-3">@if ($booking->creative_path)<a class="text-fg-link" target="_blank" rel="noopener" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($booking->creative_path) }}">{{ $booking->creative_type === 'video' ? __('View video') : __('View') }}</a>@endif
                        {{-- QC-018 (ADR 11): sponsored video creatives are uploaded by admin. --}}
                        <form method="POST" action="{{ route('admin.marketing.bookings.video', $booking->id) }}" enctype="multipart/form-data" class="mt-2 flex flex-col gap-1">
                            @csrf
                            <label class="text-xs text-fg-secondary" for="video-{{ $booking->id }}">{{ __('Upload video creative') }}</label>
                            <input id="video-{{ $booking->id }}" type="file" name="video" accept="video/mp4,video/webm,video/quicktime" required class="text-xs">
                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Upload') }}</x-ui.button>
                        </form>
                    </td>
                    <td class="px-5 py-3"><x-ui.money :amount="$booking->amount" :currency="$booking->currency" /></td>
                    <td class="px-5 py-3">@include('panels.admin.marketing._decision', ['action' => route('admin.marketing.bookings.decide', $booking->id), 'id' => 'b'.$booking->id])</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Ad packages paid by bank transfer')" flush>
        <x-ui.table :headers="[__('Package'), __('Marketer'), __('Amount'), __('Decision')]" :rows="$subscriptions->isNotEmpty()" :empty="__('Nothing waiting.')">
            @foreach ($subscriptions as $subscription)
                <tr class="border-t border-line align-top">
                    <td class="px-5 py-3">{{ $subscription->package->name_en }}</td>
                    <td class="px-5 py-3">#{{ $subscription->subscriber_id }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$subscription->amount" :currency="$subscription->currency" /></td>
                    <td class="px-5 py-3">@include('panels.admin.marketing._decision', ['action' => route('admin.marketing.subscriptions.decide', $subscription->id), 'id' => 's'.$subscription->id])</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
