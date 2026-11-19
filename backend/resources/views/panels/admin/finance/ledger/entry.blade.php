@php($debits = $transaction->entries->where('direction', 'debit'))
@php($credits = $transaction->entries->where('direction', 'credit'))
<x-layouts.panel :title="__('Journal entry JE-:id', ['id' => $transaction->id])" :subtitle="\App\Shared\Localization\Label::of($transaction->type).' · '.$transaction->posted_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i').' '.__('(Oman)')" :breadcrumbs="[__('Finance'), __('Ledger'), 'JE-'.$transaction->id]">
    <x-slot:actions>
        <x-ui.button :href="route('admin.finance.ledger.entries')" variant="ghost">{{ __('Back to journal entries') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card :title="__('Entry')">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Reference') }}</dt><dd class="font-medium">{{ $transaction->reference_type ? \App\Shared\Localization\Label::of($transaction->reference_type).' #'.$transaction->reference_id : '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Description') }}</dt><dd class="font-medium">{{ $transaction->description ?? '—' }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Posted by') }}</dt><dd class="font-medium">{{ \App\Shared\Localization\Label::of($transaction->actor_type) }}{{ $transaction->actor_user_id ? ' #'.$transaction->actor_user_id : '' }}</dd></div>
            <div class="sm:col-span-3"><dt class="text-fg-secondary">{{ __('Idempotency key') }}</dt><dd class="break-all font-mono text-xs">{{ $transaction->idempotency_key }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Debit and credit lines')" flush>
        <x-ui.table :headers="[__('Account'), __('Owner'), __('Debit amount'), __('Credit amount'), __('Balance after')]">
            @foreach ($transaction->entries as $line)
                <tr class="border-t border-line" data-ledger-line="{{ $line->id }}" data-direction="{{ $line->direction }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.ledger.entries', ['account' => $line->ledger_account_id]) }}">{{ \App\Shared\Localization\Label::of($line->account->code) }}</a><span class="block text-xs text-fg-secondary">#{{ $line->ledger_account_id }} · {{ \App\Shared\Localization\Label::of($line->account->bucket) }}</span></td>
                    <td class="px-5 py-3">{{ $owners[$line->ledger_account_id] ?? '' }}</td>
                    <td class="px-5 py-3">@if ($line->direction === 'debit')<x-ui.money :amount="(string) $line->amount" :currency="$transaction->currency" />@endif</td>
                    <td class="px-5 py-3">@if ($line->direction === 'credit')<x-ui.money :amount="(string) $line->amount" :currency="$transaction->currency" />@endif</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $line->balance_after" :currency="$transaction->currency" /></td>
                </tr>
            @endforeach
            <tr class="border-t border-line font-semibold" data-ledger-totals>
                <td class="px-5 py-3" colspan="2">{{ __('Total') }}</td>
                <td class="px-5 py-3"><x-ui.money :amount="$debits->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->amount, 4), '0')" :currency="$transaction->currency" /></td>
                <td class="px-5 py-3"><x-ui.money :amount="$credits->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->amount, 4), '0')" :currency="$transaction->currency" /></td>
                <td></td>
            </tr>
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
