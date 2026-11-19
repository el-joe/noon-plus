<x-layouts.panel :title="__('Platform overview')" :subtitle="__('Your countries · times shown in Oman time (Asia/Muscat)')"
                 :panel="$panel" :navigation="$navigation" :breadcrumbs="[__('Dashboard'), __('Overview')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('admin.dashboard', ['export' => 'csv'])">{{ __('Export report') }}</x-ui.button>
        <x-ui.button :href="route('admin.approvals')">{{ __('Open approvals') }}</x-ui.button>
    </x-slot:actions>

    {{-- QC-024: KPIs (Figma 96:1693). Money is never summed across currencies. --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Key figures') }}">
        @if ($showMoney)
            <x-ui.stat-card :label="__('GMV today')" :value="$rows->count() === 1 ? $rows->first()['gmv'].' '.$rows->first()['country']->currency : trans_choice(':count currency|:count currencies', $rows->pluck('country.currency')->unique()->count())" :delta="$rows->count() === 1 ? null : __('By country below')" />
        @endif
        <x-ui.stat-card :label="__('Orders today')" :value="$rows->sum('orders')" />
        <x-ui.stat-card :label="__('Active sellers')" :value="$rows->sum('active_vendors')" />
        <x-ui.stat-card :label="__('Pending approvals')" :value="$rows->sum('pending_approvals')" :delta="$failedJobs ? trans_choice(':count failed job in 24 h|:count failed jobs in 24 h', $failedJobs) : null" />
    </section>

    <section class="overflow-x-auto rounded-xl border border-line bg-surface" data-kpis-by-country>
        <h2 class="px-5 pt-4 pb-3 text-base font-semibold">{{ __('KPIs by country') }}</h2>
        <table class="w-full text-sm">
            <thead class="bg-subtle text-start text-xs text-fg-secondary">
            <tr>
                <th class="px-5 py-2 text-start font-medium">{{ __('Country') }}</th>
                @if ($showMoney)<th class="px-5 py-2 text-start font-medium">{{ __('GMV today') }}</th>@endif
                <th class="px-5 py-2 text-start font-medium">{{ __('Orders today') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Active sellers') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Pending approvals') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Local time') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Weekend') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr class="border-t border-line" data-country="{{ $row['country']->code }}">
                    <td class="px-5 py-3 font-medium">{{ app()->getLocale() === 'ar' ? $row['country']->name_ar : $row['country']->name_en }}</td>
                    @if ($showMoney)<td class="px-5 py-3">{{ $row['gmv'] }} {{ $row['country']->currency }}</td>@endif
                    <td class="px-5 py-3">{{ $row['orders'] }}</td>
                    <td class="px-5 py-3">{{ $row['active_vendors'] }}</td>
                    <td class="px-5 py-3">{{ $row['pending_approvals'] }}</td>
                    <td class="px-5 py-3">{{ $adminNow->setTimezone($row['country']->timezone)->format('D H:i') }}</td>
                    <td class="px-5 py-3">{{ collect($row['country']->weekend)->map(fn ($day) => __(ucfirst($day)))->implode('–') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.panel>
