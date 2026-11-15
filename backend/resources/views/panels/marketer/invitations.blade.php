<x-layouts.panel :title="__('Invitations')" :subtitle="__('Answer within the deadline — after it the slot goes to another creator')" :breadcrumbs="[__('Creator Studio'), __('Invitations')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Product'), __('Pay'), __('Answer by'), __('Status'), '']" :rows="$invitations->isNotEmpty()" :empty="__('No invitations yet.')">
            @foreach ($invitations as $invitation)
                <tr class="border-t border-line" data-invitation="{{ $invitation->id }}">
                    <td class="px-5 py-3">{{ $invitation->campaign->title }}<div class="text-xs text-fg-secondary">{{ $invitation->campaign->vendor?->store_name ?? 'Nawy' }}</div></td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->campaign->listing->variant->product->title_en }}</td>
                    <td class="px-5 py-3 text-xs">
                        @if ($invitation->influencer_fixed) <x-ui.money :amount="$invitation->influencer_fixed" :currency="$invitation->campaign->currency" /> {{ __('fixed') }}
                        @elseif ($invitation->effective_type === 'broker') {{ ($invitation->rate_snapshot['broker_fixed'] ?? 0) }} + {{ $invitation->rate_snapshot['broker_percent'] ?? 0 }}%
                        @else {{ $invitation->rate_snapshot['affiliate_percent'] ?? 0 }}% @endif
                    </td>
                    <td class="px-5 py-3 text-xs">{{ $invitation->expires_at?->format('j M H:i') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invitation->status" /></td>
                    <td class="px-5 py-3">
                        @if ($invitation->status->value === 'pending')
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('marketer.invitations.accept', $invitation->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Accept') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('marketer.invitations.decline', $invitation->id) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Decline') }}</x-ui.button></form>
                            </div>
                        @elseif ($invitation->status->value === 'accepted')
                            <a class="text-sm text-fg-link" href="{{ route('marketer.campaigns.show', $invitation->id) }}">{{ __('Open') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $invitations->links() }}
</x-layouts.panel>
