<x-layouts.panel :title="__('Earnings')" :subtitle="__('Per-stop pay · last 7 days (:tz)', ['tz' => $currentCountry->timezone])" :breadcrumbs="[__('Money'), __('Earnings')]">
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Last 7 days') }}</span>
            <span class="text-2xl font-bold" data-earnings-total>@if ($total !== null)<x-ui.money :amount="$total" :currency="$currentCountry->currency" />@else — @endif</span>
        </div>
        <x-ui.stat-card :label="__('Stops completed')" :value="$stops" />
        <x-ui.stat-card :label="__('Failed attempts')" :value="$failedTotal" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('By day')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Day'), __('Stops'), __('Failed'), __('Earned')]">
                @foreach ($days as $day)
                    <tr class="border-t border-line" data-day="{{ $day['day']->toDateString() }}">
                        <td class="px-5 py-3">{{ $day['day']->format('D j M') }}</td>
                        <td class="px-5 py-3">{{ $day['delivered'] }}</td>
                        <td class="px-5 py-3">{{ $day['failed'] }}</td>
                        <td class="px-5 py-3">@if ($day['earned'] !== null)<x-ui.money :amount="$day['earned']" :currency="$currentCountry->currency" />@else — @endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Pay rates')">
            @if ($perStop === null)
                <p class="text-sm text-fg-secondary">{{ __('Pay rates are not set for this country yet. Your stops are counted and will be paid at the agreed rate.') }}</p>
            @else
                <dl class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between"><dt>{{ __('Delivered stop') }}</dt><dd><x-ui.money :amount="(string) $perStop" :currency="$currentCountry->currency" /></dd></div>
                    <div class="flex justify-between"><dt>{{ __('Failed attempt') }}</dt><dd><x-ui.money :amount="(string) ($perFailed ?? '0')" :currency="$currentCountry->currency" /></dd></div>
                </dl>
            @endif
            <p class="mt-3 text-xs text-fg-secondary">{{ __('Earnings are paid by your company or Nawy fleet; payslips and wallet withdrawals are coming.') }}</p>
        </x-ui.card>
    </div>
</x-layouts.panel>
