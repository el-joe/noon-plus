<x-layouts.panel :title="($report->marketer?->display_name ?? '#'.$report->marketer_profile_id).' · '.$report->period" :subtitle="__('Generated :date (Oman time)', ['date' => $report->generated_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i')])" :breadcrumbs="[__('Growth'), __('Celebrity monthly report'), $report->period]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('admin.marketers.celebrity-reports', ['period' => $report->period])">{{ __('All creators') }}</x-ui.button>
        @can('marketers.manage')
            <form method="POST" action="{{ route('admin.marketers.celebrity-reports.regenerate') }}">
                @csrf
                <input type="hidden" name="period" value="{{ $report->period }}">
                <input type="hidden" name="marketer_profile_id" value="{{ $report->marketer_profile_id }}">
                <x-ui.button variant="outline" type="submit">{{ __('Regenerate') }}</x-ui.button>
            </form>
        @endcan
        <x-ui.button :href="route('admin.marketers.celebrity-reports.pdf', $report->id)">{{ __('Download PDF') }}</x-ui.button>
    </x-slot:actions>

    @include('panels._partials.celebrity-report-detail', ['report' => $report])
</x-layouts.panel>
