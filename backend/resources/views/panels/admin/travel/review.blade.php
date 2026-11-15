<x-layouts.panel :title="__('Travel packages')" :subtitle="__('Packages waiting for review')" :breadcrumbs="[__('Verticals'), __('Travel packages')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Package'), __('Agency'), __('Departures'), __('Inclusions'), __('Contract'), __('Decision')]" :rows="$packages->isNotEmpty()" :empty="__('Nothing waiting for review.')">
            @foreach ($packages as $package)
                <tr class="border-t border-line align-top" data-package="{{ $package->id }}">
                    <td class="px-5 py-3"><span class="font-medium">{{ $package->title_en }}</span><span class="block text-xs text-fg-secondary">{{ $package->destination_city }} · {{ $package->nights }} {{ __('nights') }}</span></td>
                    <td class="px-5 py-3">{{ $package->vendor->store_name }}</td>
                    <td class="px-5 py-3 text-xs">@foreach ($package->departures as $departure){{ $departure->departs_on->format('j M') }} · {{ $departure->seat_capacity }} {{ __('seats') }}<br>@endforeach</td>
                    <td class="px-5 py-3 text-xs">{{ implode(', ', $package->inclusions ?? []) }}</td>
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.travel.contract', $package->id) }}">{{ __('Download') }}</a></td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            <form method="POST" action="{{ route('admin.travel.approve', $package->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button></form>
                            <form method="POST" action="{{ route('admin.travel.reject', $package->id) }}" class="flex gap-2">@csrf
                                <label class="sr-only" for="reason-{{ $package->id }}">{{ __('Reject reason') }}</label>
                                <input id="reason-{{ $package->id }}" name="reason" required minlength="5" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $packages->links() }}
</x-layouts.panel>
