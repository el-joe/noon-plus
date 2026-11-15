<x-layouts.panel :title="__('Links & codes')" :subtitle="__('Share a link or code — sales within the attribution window are credited to you')" :breadcrumbs="[__('Creator Studio'), __('Links & codes')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Code'), __('Link'), __('Clicks'), __('Orders')]" :rows="$links->isNotEmpty()" :empty="__('Accept an invitation to get links.')">
            @foreach ($links as $row)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $row['invitation']->campaign->title }}</td>
                    <td class="px-5 py-3 font-mono">{{ $row['share']['code'] }}</td>
                    <td class="px-5 py-3 text-xs text-fg-link break-all">{{ $row['share']['url'] }}</td>
                    <td class="px-5 py-3">{{ $row['clicks'] }}</td>
                    <td class="px-5 py-3">{{ $row['orders'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
