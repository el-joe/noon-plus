<x-layouts.panel :title="__('COD cash in hand')" :breadcrumbs="[__('Money'), __('COD')]">
    <x-ui.card :title="__('Collected, not yet settled')" flush>
        <x-ui.table :headers="[__('Collected at'), __('Amount')]" :rows="$collections->isNotEmpty()" :empty="__('No cash in hand.')">
            @foreach ($collections as $collection)
                <tr class="border-t border-line"><td class="px-5 py-3 text-xs">{{ $collection->collected_at->setTimezone($currentCountry->timezone)->format('j M H:i') }}</td><td class="px-5 py-3"><x-ui.money :amount="$collection->amount" :currency="$collection->currency" /></td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    <x-ui.card :title="__('Settlements')" flush>
        <x-ui.table :headers="[__('Settlement'), __('Day'), __('Expected'), __('Status')]" :rows="$settlements->isNotEmpty()" :empty="__('No settlements yet.')">
            @foreach ($settlements as $settlement)
                <tr class="border-t border-line"><td class="px-5 py-3">{{ $settlement->number }}</td><td class="px-5 py-3 text-xs">{{ $settlement->business_date->format('j M Y') }}</td><td class="px-5 py-3"><x-ui.money :amount="$settlement->expected_amount" :currency="$settlement->currency" /></td><td class="px-5 py-3"><x-ui.status-badge :status="$settlement->status" /></td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
