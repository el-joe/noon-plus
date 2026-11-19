<x-layouts.panel :title="__('Portal analytics')" :subtitle="__('Portal page visits and registration funnels in this country (FR-POR-8)')" :breadcrumbs="[__('Operations'), __('Portal analytics')]">
    <form method="GET" class="flex gap-2">
        <x-ui.select name="days" :options="[7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days')]" :selected="(string) $days" onchange="this.form.submit()" />
    </form>

    <x-ui.card :title="__('Portal pages')" flush>
        <x-ui.table :headers="[__('Page'), __('Visitors'), __('Views')]" :rows="$report['pages'] !== []" :empty="__('No portal visits in this period.')">
            @foreach ($report['pages'] as $page)
                <tr class="border-t border-line" data-portal-page="{{ $page['page'] }}">
                    <td class="px-5 py-3 font-medium" dir="ltr">/{{ $page['page'] }}</td>
                    <td class="px-5 py-3">{{ number_format($page['visitors']) }}</td>
                    <td class="px-5 py-3">{{ number_format($page['views']) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @forelse ($report['wizards'] as $type => $wizard)
        @php($top = max(1, $wizard['steps'][0]['visitors'] ?? 1))
        <x-ui.card :title="__('Registration funnel: :type', ['type' => str_replace(['_', '-'], ' ', ucfirst($type))])" flush>
            <x-ui.table :headers="[__('Step'), __('Visitors who reached it'), __('Of the first step')]" :rows="true">
                @foreach ($wizard['steps'] as $step)
                    <tr class="border-t border-line" data-funnel-step="{{ $type }}:{{ $step['step'] }}">
                        <td class="px-5 py-3">{{ str_replace(['_', '-'], ' ', ucfirst($step['step'])) }}</td>
                        <td class="px-5 py-3">{{ number_format($step['visitors']) }}</td>
                        <td class="px-5 py-3">{{ round(100 * $step['visitors'] / $top) }}%</td>
                    </tr>
                @endforeach
                <tr class="border-t border-line font-semibold" data-funnel-submitted="{{ $type }}">
                    <td class="px-5 py-3">{{ __('Application submitted') }}</td>
                    <td class="px-5 py-3">{{ number_format($wizard['submitted']) }}</td>
                    <td class="px-5 py-3">{{ round(100 * $wizard['submitted'] / $top) }}%</td>
                </tr>
            </x-ui.table>
        </x-ui.card>
    @empty
        <x-ui.card>
            <p class="text-sm text-fg-secondary">{{ __('No registration wizard activity in this period.') }}</p>
        </x-ui.card>
    @endforelse
</x-layouts.panel>
