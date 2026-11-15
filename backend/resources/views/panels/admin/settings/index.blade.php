<x-layouts.panel :title="__('Platform settings')" :subtitle="__('Settings for :country', ['country' => $currentCountry?->name_en ?? '—'])" :breadcrumbs="[__('Platform'), __('Platform settings')]">
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($sections as $section)
            @can($section['ability'])
                <a href="{{ route($section['route']) }}" class="flex flex-col gap-1 rounded-xl border border-line bg-surface p-5 hover:border-line-brand">
                    <span class="font-semibold">{{ $section['title'] }}</span>
                    <span class="text-sm text-fg-secondary">{{ $section['text'] }}</span>
                </a>
            @endcan
        @endforeach
    </section>
</x-layouts.panel>
