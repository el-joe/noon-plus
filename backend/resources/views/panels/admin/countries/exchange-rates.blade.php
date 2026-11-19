<x-layouts.panel :error-summary="false" :title="__('Exchange rates')" :subtitle="__('Rates for gateways that cannot settle the country currency. PayPal is charged in USD at the current rate; order money stays in the country currency.')" :breadcrumbs="[__('Countries'), __('Exchange rates')]">
    @php($canManage = auth()->user()->can('exchange_rates.manage'))

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <x-ui.card :title="__('Current rates')" :subtitle="__('Provider: :driver', ['driver' => $provider])" flush>
        @if ($canManage)
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.countries.exchange-rates.sync') }}">
                    @csrf
                    <x-ui.button type="submit" variant="outline" size="sm">{{ __('Sync from provider') }}</x-ui.button>
                </form>
            </x-slot:actions>
        @endif
        <x-ui.table :headers="[__('Pair'), __('Rate'), __('Effective (Oman)'), __('Source'), __('PayPal')]" :rows="$current !== []" :empty="__('No active countries.')">
            @foreach ($current as $row)
                <tr class="border-t border-line" data-pair="{{ $row['base'] }}-{{ $row['quote'] }}">
                    <td class="px-5 py-3 font-medium">1 {{ $row['base'] }} → {{ $row['quote'] }}<span class="block text-xs text-fg-secondary">{{ $row['countries']->pluck('name_en')->join(', ') }}</span></td>
                    <td class="px-5 py-3 font-mono">{{ $row['rate'] ? rtrim(rtrim((string) $row['rate']->rate, '0'), '.') : '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row['rate']?->effective_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row['rate']?->source ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">
                        @foreach ($row['paypal'] as $entry)
                            <span class="me-2 inline-flex items-center gap-1">
                                {{ $entry['country']->name_en }}:
                                @if (! $entry['needs_rate'])
                                    <x-ui.badge tone="info">{{ __('Native') }}</x-ui.badge>
                                @elseif ($row['rate'] === null)
                                    <x-ui.badge tone="danger">{{ __('Needs a rate') }}</x-ui.badge>
                                @elseif ($entry['config']?->is_enabled)
                                    <x-ui.badge tone="success">{{ __('Enabled') }}</x-ui.badge>
                                @else
                                    <x-ui.badge>{{ __('Can be enabled') }}</x-ui.badge>
                                @endif
                            </span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if ($canManage)
        <x-ui.card :title="__('Add a rate')" :subtitle="__('A new rate applies from its time on; payments keep the rate they were charged at.')">
            <form method="POST" action="{{ route('admin.countries.exchange-rates.store') }}" class="grid gap-3 sm:grid-cols-5">
                @csrf
                <x-ui.select :label="__('From')" name="base_currency" :selected="old('base_currency')" :options="$currencies->mapWithKeys(fn ($code) => [$code => $code])->all()" />
                <x-ui.select :label="__('To')" name="quote_currency" :selected="old('quote_currency', $quotes[0] ?? 'USD')" :options="$currencies->mapWithKeys(fn ($code) => [$code => $code])->all()" />
                <x-ui.input :label="__('Rate (1 From = ? To)')" name="rate" :value="old('rate')" inputmode="decimal" required />
                <x-ui.input :label="__('Effective from (Oman time)')" name="effective_at" type="datetime-local" :value="old('effective_at')" :helper="__('Empty = now')" />
                <x-ui.input :label="__('Note')" name="note" :value="old('note')" />
                <div><x-ui.button type="submit">{{ __('Save rate') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('History')" flush>
        <x-slot:actions>
            <form method="GET" class="flex items-end gap-2">
                <x-ui.select name="base" :selected="$filters['base'] ?? ''" :options="['' => __('All currencies')] + $currencies->mapWithKeys(fn ($code) => [$code => $code])->all()" :aria-label="__('From')" />
                <x-ui.button type="submit" variant="outline" size="md">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <x-ui.table :headers="[__('Pair'), __('Rate'), __('Effective (Oman)'), __('Source'), __('Note'), __('By')]" :rows="$history->isNotEmpty()" :empty="__('No rates yet.')">
            @foreach ($history as $rate)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $rate->base_currency }} → {{ $rate->quote_currency }}</td>
                    <td class="px-5 py-3 font-mono">{{ rtrim(rtrim((string) $rate->rate, '0'), '.') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $rate->effective_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $rate->source }}</td>
                    <td class="px-5 py-3 text-xs">{{ $rate->note ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $authors[$rate->created_by] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="px-5 py-3">{{ $history->links() }}</div>
    </x-ui.card>
</x-layouts.panel>
