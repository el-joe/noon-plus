<x-layouts.panel :title="__('Packaging')" :subtitle="__('Packaging catalogue in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Packaging')]">
    @include('panels.admin.packaging._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Item'), __('Unit cost'), __('Order limits'), __('Status'), '']" :rows="$supplies->isNotEmpty()" :empty="__('No packaging items yet — stores cannot order packaging in this country.')">
            @foreach ($supplies as $supply)
                <tr class="border-t border-line" data-supply="{{ $supply->sku }}">
                    <td class="px-5 py-3 font-medium">{{ $supply->name_en }}<span class="block text-xs text-fg-secondary">{{ $supply->sku }} · <span dir="rtl">{{ $supply->name_ar }}</span></span></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $supply->unit_cost" :currency="$country->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $supply->min_quantity }} – {{ $supply->max_quantity ?? '∞' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$supply->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@can('packaging.manage')<x-ui.button variant="ghost" size="sm" :href="route('admin.packaging.supplies.edit', $supply->id)">{{ __('Edit') }}</x-ui.button>@endcan</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('packaging.manage')
        <div class="grid gap-6 xl:grid-cols-3">
            <x-ui.card :title="__('Add a packaging item')" class="xl:col-span-2">
                <form method="POST" action="{{ route('admin.packaging.supplies.store') }}" class="grid gap-3 sm:grid-cols-3">
                    @csrf
                    @include('panels.admin.packaging._supply-fields', ['supply' => null])
                    <div class="flex items-end"><x-ui.button type="submit">{{ __('Add item') }}</x-ui.button></div>
                </form>
            </x-ui.card>
            <x-ui.card :title="__('Delivery fee')" :subtitle="__('Charged in full on every packaging order (never subsidised)')">
                <form method="POST" action="{{ route('admin.packaging.delivery-fee') }}" class="flex flex-col gap-3">
                    @csrf
                    <x-ui.input :label="__('Delivery fee (:currency)', ['currency' => $country->currency])" name="delivery_fee" :value="(string) $deliveryFee->getAmount()" inputmode="decimal" required />
                    <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>
    @endcan
</x-layouts.panel>
