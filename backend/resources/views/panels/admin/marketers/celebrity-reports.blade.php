<x-layouts.panel :title="__('Celebrity monthly report')" :subtitle="__('Influencers ranked by earnings for :period · generated on the 1st for the previous month', ['period' => $period])" :breadcrumbs="[__('Growth'), __('Marketers'), __('Celebrity monthly report')]">
    <x-slot:actions>
        @can('marketers.manage')
            <form method="POST" action="{{ route('admin.marketers.celebrity-reports.regenerate') }}" class="flex items-center gap-2">
                @csrf
                <input type="month" name="period" value="{{ $period }}" aria-label="{{ __('Month') }}" class="h-10 rounded-lg border border-line bg-surface px-3 text-sm">
                <x-ui.button type="submit">{{ __('Regenerate month') }}</x-ui.button>
            </form>
        @endcan
    </x-slot:actions>

    <nav class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Months') }}">
        @foreach ($periods as $month)
            <a href="{{ route('admin.marketers.celebrity-reports', ['period' => $month]) }}" @class(['rounded-full border px-3 py-1', 'border-brand font-medium' => $month === $period, 'border-line text-fg-secondary' => $month !== $period])>{{ $month }}</a>
        @endforeach
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Creator'), __('Orders'), __('Attributed sales'), __('Conversions'), __('Commission'), __('Total earnings'), __('Obligations'), __('Emailed'), '']" :rows="$reports->isNotEmpty()" :empty="__('No reports for this month yet.')">
            @foreach ($reports as $report)
                @php($obligations = collect($report->metrics['cross_promotion'] ?? []))
                <tr class="border-t border-line" data-report="{{ $report->marketer_profile_id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.marketers.celebrity-reports.show', $report->id) }}">{{ $report->marketer?->display_name ?? '#'.$report->marketer_profile_id }}</a></td>
                    <td class="px-5 py-3">{{ $report->orders_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $report->attributed_sales" :currency="$report->currency" /></td>
                    <td class="px-5 py-3">{{ $report->conversions_count }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $report->commission_net" :currency="$report->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $report->total_earnings" :currency="$report->currency" /></td>
                    <td class="px-5 py-3">{{ $obligations->isEmpty() ? '—' : $obligations->where('status', 'met')->count().' / '.$obligations->count() }}</td>
                    <td class="px-5 py-3 text-xs">{{ $report->emailed_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') ?? '—' }}</td>
                    <td class="px-5 py-3 text-end"><x-ui.button variant="ghost" size="sm" :href="route('admin.marketers.celebrity-reports.pdf', $report->id)">{{ __('PDF') }}</x-ui.button></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $reports->links() }}
</x-layouts.panel>
