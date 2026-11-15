<x-layouts.panel :title="__('Disputes')" :subtitle="__('Customer disputes in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Disputes')]">
    <form method="GET" action="{{ route('admin.disputes') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Disputes\Enums\DisputeStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Dispute'), __('Shipment'), __('Reason'), __('Opened'), __('Status')]" :rows="$disputes->isNotEmpty()" :empty="__('No disputes.')">
            @foreach ($disputes as $dispute)
                <tr class="border-t border-line" data-dispute="{{ $dispute->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.disputes.show', $dispute->id) }}">{{ $dispute->number }}</a></td>
                    <td class="px-5 py-3">{{ $dispute->subOrder?->number }}</td>
                    <td class="px-5 py-3">{{ str_replace('_', ' ', $dispute->reason->value) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $dispute->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }} {{ __('Oman') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$dispute->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $disputes->links() }}
</x-layouts.panel>
