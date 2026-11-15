<x-layouts.panel :title="__('My ads')" :subtitle="__(':total ads · stats for the last 30 days', ['total' => $counts->sum()])" :breadcrumbs="[__('My listings'), __('All ads')]">
    <x-slot:actions>
        <x-ui.button :href="route('partner.marketplace.ads.create')">{{ __('Post an ad') }}</x-ui.button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card :label="__('Views')" :value="number_format($stats['views'])" />
        <x-ui.stat-card :label="__('Phone reveals')" :value="$stats['reveals']" />
        <x-ui.stat-card :label="__('Chats started')" :value="$stats['chats']" />
        <x-ui.stat-card :label="__('Saved by buyers')" :value="$stats['saves']" />
    </div>

    <nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Ad status') }}">
        @foreach (['' => __('All (:n)', ['n' => $counts->sum()]), 'active' => __('Live (:n)', ['n' => $counts['active'] ?? 0]), 'pending_review' => __('Pending review (:n)', ['n' => $counts['pending_review'] ?? 0]), 'sold' => __('Sold (:n)', ['n' => $counts['sold'] ?? 0]), 'expired' => __('Expired (:n)', ['n' => $counts['expired'] ?? 0])] as $key => $label)
            <a href="{{ route('partner.marketplace.ads', $key === '' ? [] : ['status' => $key]) }}" @class(['pb-2', 'border-b-2 border-brand font-semibold' => ($status ?? '') === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Ad'), __('Price'), __('Views'), __('Chats'), __('Status'), __('Actions')]" :rows="$ads->isNotEmpty()" :empty="__('No ads yet.')">
            @foreach ($ads as $ad)
                <tr class="border-t border-line align-top" data-ad="{{ $ad->id }}">
                    <td class="px-5 py-3"><span class="font-medium">{{ $ad->title }}</span>
                        <span class="block text-xs text-fg-secondary">{{ $ad->number }} · {{ $ad->category->name() }} · {{ $ad->city }}</span>
                        @if ($ad->rejection_reason)<span class="block text-xs text-fg-danger">{{ $ad->rejection_reason }}</span>@endif
                    </td>
                    <td class="px-5 py-3">@if ($ad->price !== null)<x-ui.money :amount="$ad->price" :currency="$currency" />@else — @endif</td>
                    <td class="px-5 py-3">{{ number_format($ad->views) }}</td>
                    <td class="px-5 py-3">{{ $ad->threads_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$ad->status" />
                        @if ($ad->featured_until?->isFuture())<x-ui.badge tone="info">{{ __('Featured') }}</x-ui.badge>@endif
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap gap-2">
                            @switch($ad->status->value)
                                @case('draft')
                                    <form method="POST" action="{{ route('partner.marketplace.ads.act', [$ad->id, 'submit']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Submit') }}</x-ui.button></form>
                                    @break
                                @case('pending_contract')
                                    <form method="POST" action="{{ route('partner.marketplace.ads.act', [$ad->id, 'sign']) }}" class="flex gap-2">@csrf
                                        <label class="sr-only" for="sign-{{ $ad->id }}">{{ __('Full name') }}</label>
                                        <input id="sign-{{ $ad->id }}" name="full_name" required placeholder="{{ __('Type your full name to sign') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm">{{ __('Sign & submit') }}</x-ui.button>
                                    </form>
                                    @break
                                @case('active')
                                    <x-ui.button size="sm" :href="route('partner.marketplace.ads.sold', $ad->id)">{{ __('Mark as sold') }}</x-ui.button>
                                    <form method="POST" action="{{ route('partner.marketplace.ads.act', [$ad->id, 'pause']) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Pause') }}</x-ui.button></form>
                                    @break
                                @case('paused')
                                    <form method="POST" action="{{ route('partner.marketplace.ads.act', [$ad->id, 'resume']) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Resume') }}</x-ui.button></form>
                                    @break
                                @case('expired')
                                    <form method="POST" action="{{ route('partner.marketplace.ads.act', [$ad->id, 'renew']) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Renew') }}</x-ui.button></form>
                                    @break
                                @case('sold')
                                    <span class="text-xs">{{ __('Sold · :price', ['price' => number_format((float) $ad->sold_price, 2)]) }}</span>
                                    @break
                            @endswitch
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $ads->links() }}
</x-layouts.panel>
