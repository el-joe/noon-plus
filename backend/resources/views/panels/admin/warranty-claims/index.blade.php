<x-layouts.panel :title="__('Warranty claims')" :subtitle="__('Warranty claims in :country · extended-plan claims are decided by Nawy', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Warranty claims')]">
    <form method="GET" action="{{ route('admin.warranty-claims') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')" :options="collect(\App\Domains\Warranty\Enums\WarrantyClaimStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <x-ui.select :label="__('Coverage')" name="coverage" :selected="$filters['coverage'] ?? ''" :placeholder="__('All')" :options="['standard' => __('Seller standard warranty'), 'extended' => __('Nawy extended plan')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Claim'), __('Shipment'), __('Coverage'), __('Opened (Oman)'), __('Status')]" :rows="$claims->isNotEmpty()" :empty="__('No warranty claims.')">
            @foreach ($claims as $claim)
                <tr class="border-t border-line" data-claim="{{ $claim->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.warranty-claims.show', $claim->id) }}">{{ $claim->number }}</a></td>
                    <td class="px-5 py-3">{{ $claim->subOrder?->number }}</td>
                    <td class="px-5 py-3">{{ $claim->coverage === \App\Domains\Warranty\Enums\WarrantyCoverage::Extended ? __('Extended (Nawy)') : __('Standard (seller)') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $claim->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$claim->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $claims->links() }}
</x-layouts.panel>
