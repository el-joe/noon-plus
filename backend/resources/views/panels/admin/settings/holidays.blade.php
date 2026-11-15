<x-layouts.panel :title="__('Holidays · :country', ['country' => $country->name_en])"
                 :subtitle="__('Local calendar days skipped by delivery ETAs, SLAs and payouts. Weekend: :weekend.', ['weekend' => collect($country->weekend)->map(fn ($day) => ucfirst($day))->implode('–')])"
                 :breadcrumbs="[__('Platform settings'), __('Holidays')]">
    <x-slot:actions>
        <x-ui.button variant="outline" size="sm" :href="route('admin.settings.holidays', ['year' => $year - 1])">← {{ $year - 1 }}</x-ui.button>
        <x-ui.button variant="outline" size="sm" :href="route('admin.settings.holidays', ['year' => $year + 1])">{{ $year + 1 }} →</x-ui.button>
    </x-slot:actions>
    @php($canConfigure = auth()->user()->can('calendars.configure'))

    <x-ui.card :title="__(':year holidays', ['year' => $year])">
        <div class="flex flex-col gap-3">
            @forelse ($holidays as $holiday)
                <div class="flex flex-wrap items-end gap-3 rounded-lg border border-line p-3" data-holiday="{{ $holiday->id }}">
                    @if ($canConfigure)
                        <form method="POST" action="{{ route('admin.settings.holidays.update', $holiday->id) }}" class="flex grow flex-wrap items-end gap-3">
                            @csrf
                            @method('PUT')
                            <x-ui.input :label="__('Name (EN)')" name="name_en" :id="'name_en-'.$holiday->id" :value="$holiday->name_en" required />
                            <x-ui.input :label="__('Name (AR)')" name="name_ar" :id="'name_ar-'.$holiday->id" :value="$holiday->name_ar" dir="rtl" required />
                            <x-ui.input :label="__('From')" name="starts_on" :id="'starts_on-'.$holiday->id" type="date" :value="$holiday->starts_on->toDateString()" required />
                            <x-ui.input :label="__('To')" name="ends_on" :id="'ends_on-'.$holiday->id" type="date" :value="$holiday->ends_on->toDateString()" />
                            <x-ui.checkbox :label="__('Confirmed')" name="is_confirmed" :id="'is_confirmed-'.$holiday->id" :checked="$holiday->is_confirmed" />
                            <x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.settings.holidays.destroy', $holiday->id) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button>
                        </form>
                    @else
                        <span class="grow text-sm">{{ $holiday->name_en }} · {{ $holiday->starts_on->format('j M') }} – {{ $holiday->ends_on->format('j M') }}</span>
                        <x-ui.badge :tone="$holiday->is_confirmed ? 'success' : 'warning'">{{ $holiday->is_confirmed ? __('Confirmed') : __('Expected') }}</x-ui.badge>
                    @endif
                </div>
            @empty
                <p class="text-sm text-fg-secondary">{{ __('No holidays recorded for :year.', ['year' => $year]) }}</p>
            @endforelse
        </div>
    </x-ui.card>

    @if ($canConfigure)
        <x-ui.card :title="__('Add a holiday')">
            <form method="POST" action="{{ route('admin.settings.holidays.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-ui.input :label="__('Name (EN)')" name="name_en" required />
                <x-ui.input :label="__('Name (AR)')" name="name_ar" dir="rtl" required />
                <x-ui.input :label="__('From')" name="starts_on" type="date" required />
                <x-ui.input :label="__('To')" name="ends_on" type="date" />
                <x-ui.checkbox :label="__('Confirmed')" name="is_confirmed" />
                <x-ui.button type="submit">{{ __('Add') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endif
</x-layouts.panel>
