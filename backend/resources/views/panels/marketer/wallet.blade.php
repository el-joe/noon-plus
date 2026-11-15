@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Wallet & withdrawals')" :breadcrumbs="[__('Creator Studio'), __('Earnings'), __('Wallet')]">
    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Clearing (pending)')" :value="$fmt->format((string) $balances['pending']->getAmount(), $currency)" />
        <x-ui.stat-card :label="__('Spendable')" :value="$fmt->format((string) $balances['spendable']->getAmount(), $currency)" />
        <x-ui.stat-card :label="__('Being withdrawn')" :value="$fmt->format((string) $balances['reserved']->getAmount(), $currency)" />
        <x-ui.stat-card :label="__('Clawback owed')" :value="$fmt->format((string) $balances['clawback']->negated()->getAmount(), $currency)" />
    </section>

    <x-ui.card :title="__('Request a withdrawal')">
        <form method="POST" action="{{ route('marketer.wallet.withdraw') }}" class="grid gap-4 sm:grid-cols-4">
            @csrf
            <x-ui.input :label="__('Amount')" name="amount" type="number" step="0.001" min="0" required />
            <x-ui.input :label="__('IBAN')" name="iban" required />
            <x-ui.input :label="__('Account name')" name="account_name" required />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Withdraw') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Withdrawals')" flush>
        <x-ui.table :headers="[__('Number'), __('Amount'), __('Requested'), __('Status')]" :rows="$withdrawals->isNotEmpty()">
            @foreach ($withdrawals as $withdrawal)
                <tr class="border-t border-line"><td class="px-5 py-3">{{ $withdrawal->number }}</td><td class="px-5 py-3"><x-ui.money :amount="$withdrawal->amount" :currency="$withdrawal->currency" /></td><td class="px-5 py-3 text-xs">{{ $withdrawal->created_at->format('j M Y') }}</td><td class="px-5 py-3"><x-ui.status-badge :status="$withdrawal->status" /></td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Commission history')" flush>
        <x-ui.table :headers="[__('Campaign'), __('Gross'), __('Fee'), __('Net'), __('Status')]" :rows="$conversions->isNotEmpty()">
            @foreach ($conversions as $conversion)
                <tr class="border-t border-line"><td class="px-5 py-3">{{ $conversion->campaign->title }}</td><td class="px-5 py-3"><x-ui.money :amount="$conversion->gross" :currency="$conversion->currency" /></td><td class="px-5 py-3"><x-ui.money :amount="$conversion->fee" :currency="$conversion->currency" /></td><td class="px-5 py-3"><x-ui.money :amount="$conversion->net" :currency="$conversion->currency" /></td><td class="px-5 py-3"><x-ui.status-badge :status="$conversion->status" /></td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
