<x-layouts.panel :title="__('Links & codes')" :subtitle="__('Share a link or code — sales within the attribution window are credited to you')" :breadcrumbs="[__('Creator Studio'), __('Links & codes')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Code'), __('Link'), __('QR code'), __('Clicks'), __('Orders')]" :rows="$links->isNotEmpty()" :empty="__('Accept an invitation to get links.')">
            @foreach ($links as $row)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $row['invitation']->campaign->title }}</td>
                    <td class="px-5 py-3 font-mono">{{ $row['share']['code'] }}</td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-fg-link break-all">{{ $row['share']['url'] }}</span>
                            <x-ui.button size="sm" variant="outline" data-copy-text="{{ $row['share']['url'] }}" data-copied-label="{{ __('Copied') }}">{{ __('Copy link') }}</x-ui.button>
                        </div>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <img src="{{ route('marketer.links.qr', ['invitation' => $row['invitation']->id, 'inline' => 1]) }}" alt="{{ __('QR code for :code', ['code' => $row['share']['code']]) }}" class="size-16 rounded border border-line bg-white" loading="lazy" width="64" height="64">
                            <div class="flex flex-col gap-1">
                                <x-ui.button size="sm" variant="outline" :href="route('marketer.links.qr', ['invitation' => $row['invitation']->id, 'format' => 'png'])">{{ __('Download PNG') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" :href="route('marketer.links.qr', ['invitation' => $row['invitation']->id, 'format' => 'svg'])">{{ __('Download SVG') }}</x-ui.button>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3">{{ $row['clicks'] }}</td>
                    <td class="px-5 py-3">{{ $row['orders'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
