@php($name = fn (string $code) => $countries->has($code) ? (app()->getLocale() === 'ar' ? $countries[$code]->name_ar : $countries[$code]->name_en) : strtoupper($code))
<x-layouts.panel :title="__('Cross-country reports')" :subtitle="__('From the reporting database, loaded nightly · amounts per currency, never added across currencies')" :breadcrumbs="[__('Reports'), __('Cross-country')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('admin.reports.cross-country', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'countries' => $selected, 'export' => 'csv'])">{{ __('Export CSV') }}</x-ui.button>
        @if ($canRun)
            <form method="POST" action="{{ route('admin.reports.cross-country.run') }}" class="flex items-center gap-2">
                @csrf
                <input type="date" name="date" aria-label="{{ __('Day to reload') }}" class="h-10 rounded-lg border border-line bg-surface px-3 text-sm">
                <x-ui.button type="submit">{{ __('Reload now') }}</x-ui.button>
            </form>
        @endif
    </x-slot:actions>

    <x-ui.card>
        <form method="GET" class="grid gap-3 sm:grid-cols-4" data-report-filters>
            <x-ui.input :label="__('From')" name="from" type="date" :value="$from->toDateString()" />
            <x-ui.input :label="__('To')" name="to" type="date" :value="$to->toDateString()" />
            <fieldset class="sm:col-span-2">
                <legend class="text-sm font-medium">{{ __('Countries') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3 text-sm">
                    @foreach ($countries as $code => $country)
                        <label class="flex items-center gap-1.5"><input type="checkbox" name="countries[]" value="{{ $code }}" @checked(in_array($code, $selected, true))> {{ $name($code) }}</label>
                    @endforeach
                </div>
            </fieldset>
            <div><x-ui.button type="submit">{{ __('Apply') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('GMV trend')" data-gmv-trend>
        @forelse ($trend as $currency => $points)
            @php($max = max(array_map('floatval', $points)) ?: 1)
            <div class="mb-4">
                <p class="mb-2 text-sm font-medium">{{ $currency }}</p>
                <div class="flex h-32 items-end gap-0.5" role="img" aria-label="{{ __('Daily GMV in :currency', ['currency' => $currency]) }}">
                    @foreach ($points as $day => $gmv)
                        <div class="flex-1 rounded-t bg-brand/70" style="height: {{ max(2, round((float) $gmv / $max * 100)) }}%" title="{{ $day }}: {{ app(\App\Shared\Money\MoneyFormatter::class)->format($gmv, $currency) }}"></div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-xs text-fg-secondary"><span>{{ array_key_first($points) }}</span><span>{{ array_key_last($points) }}</span></div>
            </div>
        @empty
            <p class="text-sm text-fg-secondary">{{ __('No sales in this period.') }}</p>
        @endforelse
    </x-ui.card>

    <x-ui.card :title="__('Sales by country')" flush>
        <x-ui.table :headers="[__('Country'), __('Currency'), __('Orders'), __('GMV'), __('Average order'), __('Units'), __('New buyers'), __('Cancelled'), __('Returns'), __('Refunds')]" :rows="$sales->isNotEmpty()" :empty="__('No sales in this period.')">
            @foreach ($sales as $row)
                <tr class="border-t border-line" data-sales="{{ $row->country_code }}-{{ $row->currency }}">
                    <td class="px-5 py-3 font-medium">{{ $name($row->country_code) }}</td>
                    <td class="px-5 py-3">{{ $row->currency }}</td>
                    <td class="px-5 py-3">{{ (int) $row->orders_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $row->gmv" :currency="$row->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $row->average_order_value" :currency="$row->currency" /></td>
                    <td class="px-5 py-3">{{ (int) $row->items_sold }}</td>
                    <td class="px-5 py-3">{{ (int) $row->new_buyers }}</td>
                    <td class="px-5 py-3">{{ (int) $row->cancelled_orders }}</td>
                    <td class="px-5 py-3">{{ (int) $row->returns_requested }}</td>
                    <td class="px-5 py-3">{{ (int) $row->refunds_count }} · <x-ui.money :amount="(string) $row->refunds_amount" :currency="$row->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card :title="__('Sellers')" flush>
            <x-ui.table :headers="[__('Country'), __('Active stores'), __('New stores'), __('Peak selling stores'), __('Active listings')]" :rows="$sellers->isNotEmpty()">
                @foreach ($sellers as $row)
                    <tr class="border-t border-line" data-sellers="{{ $row->country_code }}">
                        <td class="px-5 py-3 font-medium">{{ $name($row->country_code) }}</td>
                        <td class="px-5 py-3">{{ $row->active_sellers }}</td>
                        <td class="px-5 py-3">{{ $row->new_sellers }}</td>
                        <td class="px-5 py-3">{{ $row->peak_selling_sellers }}</td>
                        <td class="px-5 py-3">{{ $row->active_listings }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Marketers')" flush>
            <x-ui.table :headers="[__('Country'), __('Currency'), __('Active'), __('New'), __('Conversions'), __('Attributed sales'), __('Commission'), __('Withdrawn')]" :rows="$marketers->isNotEmpty()">
                @foreach ($marketers as $row)
                    <tr class="border-t border-line" data-marketers="{{ $row->country_code }}-{{ $row->currency }}">
                        <td class="px-5 py-3 font-medium">{{ $name($row->country_code) }}</td>
                        <td class="px-5 py-3">{{ $row->currency }}</td>
                        <td class="px-5 py-3">{{ (int) $row->active_marketers }}</td>
                        <td class="px-5 py-3">{{ (int) $row->new_marketers }}</td>
                        <td class="px-5 py-3">{{ (int) $row->conversions_count }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) $row->attributed_sales" :currency="$row->currency" /></td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) $row->commissions_net" :currency="$row->currency" /></td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) $row->withdrawals_processed" :currency="$row->currency" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Vendor payouts')" flush>
            <x-ui.table :headers="[__('Country'), __('Currency'), __('Generated'), __('Completed')]" :rows="$payouts->isNotEmpty()">
                @foreach ($payouts as $row)
                    <tr class="border-t border-line" data-payouts="{{ $row->country_code }}-{{ $row->currency }}">
                        <td class="px-5 py-3 font-medium">{{ $name($row->country_code) }}</td>
                        <td class="px-5 py-3">{{ $row->currency }}</td>
                        <td class="px-5 py-3">{{ (int) $row->generated_count }} · <x-ui.money :amount="(string) $row->generated_amount" :currency="$row->currency" /></td>
                        <td class="px-5 py-3">{{ (int) $row->completed_count }} · <x-ui.money :amount="(string) $row->completed_amount" :currency="$row->currency" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Platform revenue')" flush>
            <x-ui.table :headers="[__('Country'), __('Account'), __('Amount')]" :rows="$revenue->isNotEmpty()">
                @foreach ($revenue as $row)
                    <tr class="border-t border-line" data-revenue="{{ $row->country_code }}-{{ $row->account_code }}">
                        <td class="px-5 py-3 font-medium">{{ $name($row->country_code) }}</td>
                        <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($row->account_code) }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) $row->amount" :currency="$row->currency" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Data loads')" flush>
        <x-ui.table :headers="[__('Country'), __('Days loaded'), __('Status'), __('Rows'), __('Finished (Oman)'), __('Error')]" :rows="$runs->isNotEmpty()" :empty="__('The reporting data has not been loaded yet.')">
            @foreach ($runs as $code => $run)
                <tr class="border-t border-line" data-etl-run="{{ $code }}">
                    <td class="px-5 py-3 font-medium">{{ $name($code) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $run->date_from->format('j M Y') }} – {{ $run->date_to->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$run->status" /></td>
                    <td class="px-5 py-3">{{ $run->rows_written }}</td>
                    <td class="px-5 py-3 text-xs">{{ $run->finished_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs text-fg-danger">{{ $run->error ? \Illuminate\Support\Str::limit($run->error, 120) : '' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
