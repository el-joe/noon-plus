<x-layouts.panel :title="__('Flash sales')" :subtitle="__('Flash sales you are invited to promote')" :breadcrumbs="[__('Campaigns'), __('Flash sales')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Flash sale'), __('Starts (Oman)'), __('Ends (Oman)'), __('Your bonus'), __('Status'), '']" :rows="$invites->isNotEmpty()" :empty="__('No flash-sale invitations yet.')">
            @foreach ($invites as $invite)
                @php($event = $invite->event)
                <tr class="border-t border-line" data-invite="{{ $invite->id }}">
                    <td class="px-5 py-3 font-medium">{{ $event->display_name ?: $event->name }}<span class="block text-xs text-fg-secondary"><x-ui.status-badge :status="$event->status" /></span></td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($event->starts_at)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($event->ends_at)->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">
                        @if ($event->marketer_bonus_type)
                            {{ (string) $event->marketer_bonus_value }} {{ $event->marketer_bonus_type->value === 'percent' ? '%' : __('per unit') }}
                        @else
                            {{ __('Country flash-sale bonus') }}
                        @endif
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invite->status" /></td>
                    <td class="px-5 py-3 text-end">
                        @if ($invite->status === \App\Domains\FlashSales\Enums\FlashSaleInviteStatus::Pending && ! in_array($event->status->value, ['ended', 'cancelled'], true))
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('marketer.flash-sales.respond', [$invite->id, 'accept']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Accept') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('marketer.flash-sales.respond', [$invite->id, 'decline']) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Decline') }}</x-ui.button></form>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $invites->links() }}
</x-layouts.panel>
