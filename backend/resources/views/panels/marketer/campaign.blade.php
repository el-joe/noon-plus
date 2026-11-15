@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="$campaign->title" :subtitle="($campaign->vendor?->store_name ?? 'Nawy').' · '.$campaign->listing->variant->product->title_en" :breadcrumbs="[__('Creator Studio'), __('Campaigns'), $campaign->number]">
    <x-slot:actions><x-ui.status-badge :status="$campaign->status" /></x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Contract')" data-contract-box>
            @if ($contractAccepted)
                <x-ui.badge tone="success">{{ __('Marketer contract accepted') }}</x-ui.badge>
            @else
                <p class="text-sm">{{ __('Accept the marketer contract to take part.') }}</p>
                <x-ui.button size="sm" class="mt-2" :href="route('marketer.contract')">{{ __('Review and sign') }}</x-ui.button>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Referral link')" data-referral-card>
            <p class="font-mono text-xl font-bold">{{ $share['code'] }}</p>
            <p class="break-all text-xs text-fg-link">{{ $share['url'] }}</p>
            <p class="mt-2 text-xs text-fg-secondary">{{ __(':clicks clicks · QR payload below', ['clicks' => $clicks]) }}</p>
            <input readonly class="mt-1 w-full rounded border border-line px-2 py-1 text-xs" value="{{ $share['qr_payload'] }}" aria-label="{{ __('QR payload') }}">
        </x-ui.card>

        <x-ui.card :title="__('Your pay')">
            @if ($invitation->influencer_fixed)
                <p class="text-lg font-semibold">{{ $fmt->format($invitation->influencer_fixed, $campaign->currency) }}</p>
                <p class="text-xs text-fg-secondary">{{ __('Paid when the campaign completes (:status)', ['status' => $invitation->influencer_fixed_status]) }}</p>
            @else
                <p class="text-sm">{{ $invitation->effective_type === 'broker' ? ($invitation->rate_snapshot['broker_fixed'] ?? 0).' + '.($invitation->rate_snapshot['broker_percent'] ?? 0).'%' : ($invitation->rate_snapshot['affiliate_percent'] ?? 0).'%' }} {{ __('per sale') }}</p>
            @endif
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Sample tracker')" flush>
        <x-ui.table :headers="[__('Qty'), __('Status'), __('Tracking'), '']" :rows="$samples->isNotEmpty()" :empty="__('No sample for this campaign.')">
            @foreach ($samples as $sample)
                <tr class="border-t border-line" data-sample="{{ $sample->id }}">
                    <td class="px-5 py-3">{{ $sample->quantity }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$sample->status" />@if ($sample->review_due_at)<div class="text-xs">{{ __('Review due :d', ['d' => $sample->review_due_at->format('j M')]) }}</div>@endif</td>
                    <td class="px-5 py-3 text-xs">{{ $sample->tracking_number ?? '—' }}</td>
                    <td class="px-5 py-3">
                        @if ($sample->status->value === 'planned')
                            <form method="POST" action="{{ route('marketer.samples.request', $sample->id) }}" class="grid gap-2 sm:grid-cols-4">@csrf
                                <input name="name" placeholder="{{ __('Name') }}" class="h-8 rounded border border-line px-2 text-xs" required>
                                <input name="phone" placeholder="{{ __('Phone') }}" class="h-8 rounded border border-line px-2 text-xs" required>
                                <input name="city" placeholder="{{ __('City') }}" class="h-8 rounded border border-line px-2 text-xs" required>
                                <input name="street" placeholder="{{ __('Street') }}" class="h-8 rounded border border-line px-2 text-xs" required>
                                <x-ui.button type="submit" size="sm">{{ __('Send address') }}</x-ui.button>
                            </form>
                        @elseif ($sample->status->value === 'delivered')
                            <form method="POST" action="{{ route('marketer.samples.received', $sample->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('I received it') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Content')">
        <form method="POST" action="{{ route('marketer.campaigns.content', $invitation->id) }}" class="grid gap-3 sm:grid-cols-3">
            @csrf
            <x-ui.select :label="__('Platform')" name="platform" :options="['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'snapchat' => 'Snapchat', 'youtube' => 'YouTube', 'x' => 'X']" />
            <x-ui.input :label="__('Post link')" name="url" type="url" required />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Submit') }}</x-ui.button></div>
        </form>
        <ul class="mt-3 flex flex-col gap-1 text-sm">
            @foreach ($content as $submission)<li>{{ $submission->platform }} — <x-ui.status-badge :status="$submission->status" /> {{ $submission->rejection_reason }}</li>@endforeach
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Conversions')" flush>
        <x-ui.table :headers="[__('Date'), __('Attribution'), __('Net'), __('Status')]" :rows="$conversions->isNotEmpty()" :empty="__('No sales yet.')">
            @foreach ($conversions as $conversion)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 text-xs">{{ $conversion->created_at->format('j M Y') }}</td>
                    <td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', $conversion->attribution) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$conversion->net" :currency="$conversion->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$conversion->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
