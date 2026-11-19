@php($canManage = app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'travel_agency', 'promotions', true))
<x-layouts.panel :title="__('Promotions')" :subtitle="__('Listing trips is free. Pay only to show a trip first, with a Sponsored label.')" :breadcrumbs="[__('Travel Partner'), __('Promotions')]">
    @if ($canManage)
        <x-slot:actions>
            <x-ui.button :href="route('travel_agency.promotions.create')">{{ __('Promote a trip') }}</x-ui.button>
        </x-slot:actions>
    @endif

    <x-ui.card flush>
        <x-ui.table :headers="[__('Promotion'), __('Trip'), __('Option'), __('Amount'), __('Window'), __('Status'), '']" :rows="$promotions->isNotEmpty()" :empty="__('No promotions yet.')">
            @foreach ($promotions as $promotion)
                <tr class="border-t border-line" data-promotion="{{ $promotion->id }}">
                    <td class="px-5 py-3 font-medium">{{ $promotion->number }}<span class="block text-xs text-fg-secondary">{{ $promotion->created_at->format('j M Y') }}</span></td>
                    <td class="px-5 py-3">{{ $promotion->package?->localizedTitle() }}</td>
                    <td class="px-5 py-3">{{ $promotion->product?->localizedName() }}<span class="block text-xs text-fg-secondary">{{ trans_choice(':count day|:count days', $promotion->duration_days, ['count' => $promotion->duration_days]) }}</span></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $promotion->amount" :currency="$promotion->currency" /></td>
                    <td class="px-5 py-3 text-xs">
                        @if ($promotion->starts_at)
                            {{ $promotion->starts_at->setTimezone($timezone)->format('j M Y, H:i') }} → {{ $promotion->ends_at?->setTimezone($timezone)->format('j M Y, H:i') }}
                        @else
                            {{ __('Starts once paid') }}
                        @endif
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$promotion->status" /></td>
                    <td class="px-5 py-3 text-end">
                        @if ($canManage && $promotion->status === \App\Domains\Advertising\Enums\TravelPromotionStatus::PendingPayment)
                            <form method="POST" action="{{ route('travel_agency.promotions.pay', $promotion->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Pay by card') }}</x-ui.button></form>
                        @elseif ($promotion->paid_at)
                            <a class="text-fg-link" href="{{ route('travel_agency.promotions.receipt', $promotion->id) }}">{{ __('Receipt') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $promotions->links() }}
</x-layouts.panel>
