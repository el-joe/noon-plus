<x-layouts.panel :title="__('Returns')" :subtitle="__('Customer returns in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Returns')]">
    <form method="GET" action="{{ route('admin.returns') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Returns\Enums\ReturnStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Return'), __('Shipment'), __('Seller'), __('Items'), __('Requested'), __('Status')]" :rows="$returns->isNotEmpty()" :empty="__('No returns.')">
            @foreach ($returns as $return)
                <tr class="border-t border-line" data-return="{{ $return->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.returns.show', $return->id) }}">{{ $return->number }}</a></td>
                    <td class="px-5 py-3">{{ $return->subOrder->number }}</td>
                    <td class="px-5 py-3">{{ $return->vendor->store_name }}</td>
                    <td class="px-5 py-3">{{ $return->items_count }}</td>
                    <td class="px-5 py-3 text-xs">{{ $return->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }} {{ __('Oman') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$return->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $returns->links() }}
</x-layouts.panel>
