<x-layouts.panel :title="__('Packages & trips')" :subtitle="__('Draft, submit for review, sell')" :breadcrumbs="[__('Travel Partner'), __('Packages & trips')]">
    <x-slot:actions>
        <x-ui.button :href="route('travel_agency.packages.create')">{{ __('New package') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Package'), __('Destination'), __('Nights'), __('Departures'), __('Status'), '']" :rows="$packages->isNotEmpty()" :empty="__('No packages yet.')">
            @foreach ($packages as $package)
                <tr class="border-t border-line" data-package="{{ $package->id }}">
                    <td class="px-5 py-3 font-medium">{{ $package->title_en }}
                        @if ($package->rejection_reason)<span class="block text-xs text-fg-danger">{{ $package->rejection_reason }}</span>@endif
                    </td>
                    <td class="px-5 py-3">{{ $package->destination_city }}</td>
                    <td class="px-5 py-3">{{ $package->nights }}</td>
                    <td class="px-5 py-3">{{ $package->departures_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$package->status" /></td>
                    <td class="px-5 py-3 text-end"><a class="text-fg-link" href="{{ route('travel_agency.packages.edit', $package->id) }}">{{ __('Edit') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $packages->links() }}
</x-layouts.panel>
