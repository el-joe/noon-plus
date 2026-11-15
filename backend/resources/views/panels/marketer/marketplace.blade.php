<x-layouts.panel :title="__('Campaign marketplace')" :subtitle="__('Live campaigns open to every affiliate and broker of your country')" :breadcrumbs="[__('Creator Studio'), __('Marketplace')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Campaign'), __('Product'), __('Ends'), '']" :rows="$campaigns->isNotEmpty()" :empty="__('No open campaigns right now.')">
            @foreach ($campaigns as $campaign)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $campaign->title }}</td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->listing->variant->product->title_en }}</td>
                    <td class="px-5 py-3 text-xs">{{ $campaign->ends_at?->format('j M Y') ?? '—' }}</td>
                    <td class="px-5 py-3"><form method="POST" action="{{ route('marketer.marketplace.join', $campaign->id) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Join') }}</x-ui.button></form></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $campaigns->links() }}
</x-layouts.panel>
