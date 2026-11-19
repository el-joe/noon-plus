<x-layouts.panel :title="__('Ledger')" :subtitle="__('Double-entry ledger of :country · read-only · balances on each account\'s normal side', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Ledger')]">
    @include('panels.admin.finance.ledger._nav', ['section' => 'accounts'])

    <x-ui.card :title="__('Balances per currency')" :subtitle="__('Every owner and bucket of an account code added up')" flush>
        <x-ui.table :headers="[__('Account'), __('Normal side'), __('Currency'), __('Accounts'), __('Balance')]" :rows="$totals->isNotEmpty()" :empty="__('Nothing has been posted to the ledger yet.')">
            @foreach ($totals as $total)
                <tr class="border-t border-line" data-ledger-total="{{ $total->code->value }}:{{ $total->currency }}">
                    <td class="px-5 py-3 font-medium">{{ \App\Shared\Localization\Label::of($total->code) }}<span class="block text-xs text-fg-secondary">{{ $total->code->value }}</span></td>
                    <td class="px-5 py-3">{{ $total->code->normalSide() === 'debit' ? __('Debit-normal') : __('Credit-normal') }}</td>
                    <td class="px-5 py-3">{{ $total->currency }}</td>
                    <td class="px-5 py-3">{{ $total->accounts }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $total->balance" :currency="$total->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <form method="GET" action="{{ route('admin.finance.ledger') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Account')" name="code" :use-old="false" :selected="$filters['code'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Finance\Enums\LedgerAccountCode::cases())->mapWithKeys(fn ($code) => [$code->value => \App\Shared\Localization\Label::of($code)])->all()" />
        <x-ui.select :label="__('Owner')" name="owner_type" :use-old="false" :selected="$filters['owner_type'] ?? ''" :placeholder="__('All')" :options="$ownerTypes->mapWithKeys(fn ($type) => [$type => \App\Shared\Localization\Label::of($type)])->all()" />
        <x-ui.select :label="__('Currency')" name="currency" :use-old="false" :selected="$filters['currency'] ?? ''" :placeholder="__('All')" :options="$currencies->mapWithKeys(fn ($currency) => [$currency => $currency])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card :title="__('Accounts')" flush>
        <x-ui.table :headers="[__('Account'), __('Owner'), __('Bucket'), __('Currency'), __('Balance'), '']" :rows="$accounts->isNotEmpty()" :empty="__('No ledger accounts match.')">
            @foreach ($accounts as $account)
                <tr class="border-t border-line" data-ledger-account="{{ $account->id }}">
                    <td class="px-5 py-3 font-medium">{{ \App\Shared\Localization\Label::of($account->code) }}<span class="block text-xs text-fg-secondary">#{{ $account->id }} · {{ $account->code->value }}</span></td>
                    <td class="px-5 py-3">{{ $owners[$account->id] ?? '' }}</td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($account->bucket) }}</td>
                    <td class="px-5 py-3">{{ $account->currency }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $account->balance" :currency="$account->currency" /></td>
                    <td class="px-5 py-3 text-end"><a class="text-fg-link" href="{{ route('admin.finance.ledger.entries', ['account' => $account->id]) }}">{{ __('Entries') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $accounts->links() }}
</x-layouts.panel>
