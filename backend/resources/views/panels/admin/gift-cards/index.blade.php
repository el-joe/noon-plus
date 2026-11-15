<x-layouts.panel :title="__('Gift cards')" :subtitle="__('Gift cards in :country · codes are never shown', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Gift cards & vouchers')]">
    @include('panels.admin.gift-cards._nav')

    <form method="GET" action="{{ route('admin.gift-cards') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Number, recipient or last 4')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\GiftCards\Enums\GiftCardStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <x-ui.select :label="__('Source')" name="source" :selected="$filters['source'] ?? ''" :placeholder="__('All')" :options="['purchase' => __('Bought'), 'admin' => __('Issued by Nawy')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Card'), __('Recipient'), __('Amount'), __('Balance'), __('Expires (Oman)'), __('Status')]" :rows="$cards->isNotEmpty()" :empty="__('No gift cards yet.')">
            @foreach ($cards as $card)
                <tr class="border-t border-line" data-gift-card="{{ $card->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.gift-cards.show', $card->id) }}">{{ $card->number }}</a><span class="block font-mono text-xs text-fg-secondary">{{ $card->maskedCode() }}</span></td>
                    <td class="px-5 py-3">{{ $card->recipient_name ?? '—' }}<span class="block text-xs text-fg-secondary">{{ $card->recipient_email }}</span></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $card->amount" :currency="$card->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $card->balance" :currency="$card->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $card->expires_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$card->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $cards->links() }}

    @can('gift_cards.manage')
        <x-ui.card :title="__('Issue a promotional gift card')" :subtitle="__('Booked as promo expense; emailed to the recipient at once.')">
            <form method="POST" action="{{ route('admin.gift-cards.issue') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Amount (:currency)', ['currency' => $country->currency])" name="amount" inputmode="decimal" required />
                <x-ui.input :label="__('Recipient email')" name="recipient_email" type="email" required />
                <x-ui.input :label="__('Recipient name')" name="recipient_name" />
                <x-ui.input :label="__('Message')" name="message" />
                <div><x-ui.button type="submit">{{ __('Issue gift card') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
