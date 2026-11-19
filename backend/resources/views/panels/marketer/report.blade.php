<x-layouts.panel :title="__('Monthly report :period', ['period' => $report->period])" :subtitle="__('Generated :date (Oman time)', ['date' => $report->generated_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i')])" :breadcrumbs="[__('Creator Studio'), __('Earnings'), __('Monthly reports'), $report->period]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('marketer.reports')">{{ __('All reports') }}</x-ui.button>
        <x-ui.button :href="route('marketer.reports.pdf', $report->id)">{{ __('Download PDF') }}</x-ui.button>
    </x-slot:actions>

    @include('panels._partials.celebrity-report-detail', ['report' => $report])
</x-layouts.panel>
