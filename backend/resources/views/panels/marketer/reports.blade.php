@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Monthly reports')" :subtitle="__('Your sales, conversions and earnings for each month, generated on the 1st')" :breadcrumbs="[__('Creator Studio'), __('Earnings'), __('Monthly reports')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Month'), __('Orders'), __('Attributed sales'), __('Conversions'), __('Total earnings'), '']" :rows="$reports->isNotEmpty()" :empty="__('Your first monthly report appears on the 1st of next month.')">
            @foreach ($reports as $report)
                <tr class="border-t border-line" data-report="{{ $report->period }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('marketer.reports.show', $report->id) }}">{{ $report->period }}</a></td>
                    <td class="px-5 py-3">{{ $report->orders_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $report->attributed_sales" :currency="$report->currency" /></td>
                    <td class="px-5 py-3">{{ $report->conversions_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $report->total_earnings" :currency="$report->currency" /></td>
                    <td class="px-5 py-3 text-end"><x-ui.button variant="ghost" size="sm" :href="route('marketer.reports.pdf', $report->id)">{{ __('Download PDF') }}</x-ui.button></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $reports->links() }}
</x-layouts.panel>
