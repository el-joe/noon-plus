<x-layouts.panel :title="__('Join options')" :subtitle="__('Sections shown on the portal /join page in :country', ['country' => $currentCountry?->name_en])" :breadcrumbs="[__('Contracts'), __('Join options')]">
    <x-ui.card :title="__('Sections')" :subtitle="__('A switched-off section is hidden on /join and its wizards refuse new applications.')">
        <form method="POST" action="{{ route('admin.join-options.save') }}" class="flex flex-col gap-3" data-join-options>
            @csrf
            @method('PUT')
            @foreach ($options as $option)
                <label class="flex items-start gap-3 rounded-lg border border-line p-3">
                    <input type="checkbox" name="enabled[]" value="{{ $option['section'] }}" @checked($option['enabled']) class="mt-1 size-4 accent-brand">
                    <span class="flex flex-col">
                        <span class="text-sm font-medium">{{ $option['label'] }}</span>
                        <span class="text-xs text-fg-secondary">{{ collect($option['types'])->map(fn ($type) => $type['label'].' ('.count($type['steps']).' '.__('steps').')')->implode(' · ') }}</span>
                    </span>
                </label>
            @endforeach
            <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
