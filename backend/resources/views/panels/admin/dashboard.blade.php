<x-layouts.panel :title="__('Platform overview')" :subtitle="__('All countries · times shown in Oman time (Asia/Muscat)')"
                 :panel="$panel" :navigation="$navigation" :breadcrumbs="[__('Dashboard'), __('Overview')]">
    <x-slot:actions>
        <x-ui.button variant="outline">{{ __('Export report') }}</x-ui.button>
        <x-ui.button :href="route('admin.approvals')">{{ __('Open approvals') }}</x-ui.button>
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Key figures') }}">
        <x-ui.stat-card :label="__('Active countries')" :value="$countries->count()" />
        <x-ui.stat-card :label="__('Admin time now')" :value="$adminNow->format('H:i')" :delta="$adminNow->format('D j M')" />
    </section>

    <section class="overflow-hidden rounded-xl border border-line bg-surface">
        <h2 class="px-5 pt-4 pb-3 text-base font-semibold">{{ __('Country clocks') }}</h2>
        <table class="w-full text-sm">
            <thead class="bg-subtle text-start text-xs text-fg-secondary">
            <tr>
                <th class="px-5 py-2 text-start font-medium">{{ __('Country') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Currency') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Local time') }}</th>
                <th class="px-5 py-2 text-start font-medium">{{ __('Weekend') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($countries as $country)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-medium">{{ $country->name_en }}</td>
                    <td class="px-5 py-3">{{ $country->currency }}</td>
                    <td class="px-5 py-3">{{ $adminNow->setTimezone($country->timezone)->format('D H:i') }}</td>
                    <td class="px-5 py-3">{{ collect($country->weekend)->map(fn ($day) => ucfirst($day))->implode('–') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.panel>
