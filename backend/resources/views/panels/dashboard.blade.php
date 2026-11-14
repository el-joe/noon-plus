<x-layouts.panel :panel="$panel" :title="__('Welcome, :name', ['name' => auth()->user()->name])" :subtitle="$panel->name" :navigation="$navigation">
    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="mb-3 text-base font-semibold">{{ __('Your access') }}</h2>
        <ul class="flex flex-col gap-2 text-sm">
            @foreach ($memberships as $membership)
                <li class="flex items-center gap-2">
                    <x-ui.badge tone="brand">{{ $membership->role->value }}</x-ui.badge>
                    <span class="text-fg-secondary">{{ $membership->country?->name_en ?? __('All countries') }}</span>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.panel>
