<x-layouts.panel :title="__('Journal entries')" :subtitle="__('Ledger of :country · one balanced entry per financial event · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Ledger'), __('Journal entries')]">
    <x-slot:actions>
        <x-ui.button :href="route('admin.finance.ledger.entries.export', array_filter($filters, fn ($value) => $value !== null && $value !== ''))" variant="outline" data-ledger-export>{{ __('Export CSV') }}</x-ui.button>
    </x-slot:actions>

    @include('panels.admin.finance.ledger._nav', ['section' => 'entries'])

    <form method="GET" action="{{ route('admin.finance.ledger.entries') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-5" role="search">
        <x-ui.input :label="__('From')" name="from" type="date" :value="$filters['from'] ?? ''" />
        <x-ui.input :label="__('To')" name="to" type="date" :value="$filters['to'] ?? ''" />
        <x-ui.input :label="__('Account ID')" name="account" type="number" min="1" :value="$filters['account'] ?? ''" :helper="$account ? \App\Shared\Localization\Label::of($account->code).' · '.$account->owner_type.' #'.$account->owner_id.' · '.$account->currency : null" />
        <x-ui.select :label="__('Reference type')" name="reference_type" :use-old="false" :selected="$filters['reference_type'] ?? ''" :placeholder="__('All')" :options="$referenceTypes" />
        <div class="flex items-end gap-2">
            <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
            <a href="{{ route('admin.finance.ledger.entries') }}" class="px-2 pb-2 text-sm text-fg-link">{{ __('Clear') }}</a>
        </div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Entry'), __('Posted (Oman)'), __('Type'), __('Reference'), __('Description'), __('Lines'), __('Amount')]" :rows="$transactions->isNotEmpty()" :empty="__('No journal entries match.')">
            @foreach ($transactions as $transaction)
                <tr class="border-t border-line" data-ledger-entry="{{ $transaction->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.ledger.entries.show', $transaction->id) }}">JE-{{ $transaction->id }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $transaction->posted_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($transaction->type) }}</td>
                    <td class="px-5 py-3">{{ $transaction->reference_type ? \App\Shared\Localization\Label::of($transaction->reference_type).' #'.$transaction->reference_id : '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $transaction->description ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $transaction->entries_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $transaction->total" :currency="$transaction->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $transactions->links() }}
</x-layouts.panel>
