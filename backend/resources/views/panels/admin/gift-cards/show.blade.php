<x-layouts.panel :title="$card->number" :subtitle="__('Gift card in :country · :code', ['country' => $country->name_en, 'code' => $card->maskedCode()])" :breadcrumbs="[__('Marketing'), __('Gift cards'), $card->number]">
    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Amount')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $card->amount, $card->currency)" />
        <x-ui.stat-card :label="__('Balance')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $card->balance, $card->currency)" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5"><span class="text-sm text-fg-secondary">{{ __('Status') }}</span><x-ui.status-badge :status="$card->status" class="self-start" /></div>
    </section>

    <x-ui.card :title="__('Details')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Source') }}</dt><dd>{{ $card->source === 'admin' ? __('Issued by Nawy') : __('Bought by customer #:id', ['id' => $card->purchaser_user_id]) }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Recipient') }}</dt><dd>{{ $card->recipient_name ?? '—' }} · {{ $card->recipient_email }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Delivered') }}</dt><dd>{{ $card->delivered_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? ($card->send_at ? __('Scheduled for :at', ['at' => $card->send_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i')]) : '—') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Redeemed') }}</dt><dd>{{ $card->redeemed_at ? $card->redeemed_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i').' · #'.$card->redeemed_by_user_id : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Expires') }}</dt><dd>{{ $card->expires_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Message') }}</dt><dd>{{ $card->message ?? '—' }}</dd></div>
        </dl>
    </x-ui.card>

    @can('gift_cards.manage')
        @if (in_array($card->status, [\App\Domains\GiftCards\Enums\GiftCardStatus::PendingPayment, \App\Domains\GiftCards\Enums\GiftCardStatus::Scheduled, \App\Domains\GiftCards\Enums\GiftCardStatus::Active], true))
            <x-ui.card :title="__('Void this card')" :subtitle="$card->source === 'admin' ? __('The unused promotional value goes back to the promo budget.') : __('Only unpaid purchases can be voided; a paid card is refunded through its payment.')">
                <form method="POST" action="{{ route('admin.gift-cards.void', $card->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.input :label="__('Reason')" name="reason" required />
                    <x-ui.button type="submit" variant="danger">{{ __('Void card') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    @endcan

    @include('panels.admin._partials.transitions', ['transitions' => $card->transitions])
</x-layouts.panel>
