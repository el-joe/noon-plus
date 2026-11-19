<x-layouts.panel :title="__('Removals')" :subtitle="__('Seller requests to take stock back or dispose of it')" :breadcrumbs="[__('Commerce'), __('Inventory & fulfilment'), __('Removals')]">
    @include('panels.admin.inventory._nav')

    <form method="GET" class="flex items-end gap-3">
        <x-ui.select :label="__('Status')" name="status" :selected="$status" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Fulfilment\Enums\RemovalStatus::cases())->mapWithKeys(fn ($case) => [$case->value => \App\Shared\Localization\Label::of($case)])->all()" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Removal'), __('Store'), __('Warehouse'), __('Type'), __('Lines'), __('Fee'), __('Status'), '']" :rows="$removals->isNotEmpty()" :empty="__('No removal requests.')">
            @foreach ($removals as $removal)
                <tr class="border-t border-line align-top" data-removal="{{ $removal->id }}">
                    <td class="px-5 py-3 font-medium">{{ $removal->number }}<span class="block text-xs text-fg-secondary">{{ $removal->reason }}</span></td>
                    <td class="px-5 py-3">{{ $removal->vendor->store_name }}</td>
                    <td class="px-5 py-3">{{ $removal->warehouse->code }}</td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($removal->type) }}
                        @if ($removal->address)<span class="block text-xs text-fg-secondary">{{ collect($removal->address)->filter()->implode(', ') }}</span>@endif</td>
                    <td class="px-5 py-3">
                        @foreach ($removal->lines as $line)
                            <span class="block">{{ $line->listing->variant->sku }} × {{ $line->quantity }} ({{ \App\Shared\Localization\Label::of($line->source) }})</span>
                        @endforeach
                    </td>
                    <td class="px-5 py-3"><x-ui.money :amount="$removal->fee" :currency="$removal->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$removal->status" /></td>
                    <td class="px-5 py-3">
                        @can('fbn.operate')
                            @if ($removal->status->value === 'requested')
                                <form method="POST" action="{{ route('admin.inventory.removals.act', [$removal->id, 'process']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Pick units') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('admin.inventory.removals.act', [$removal->id, 'reject']) }}" class="mt-2 flex items-end gap-2">@csrf
                                    <x-ui.input :label="__('Reason')" name="reason" :id="'reject-'.$removal->id" />
                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('Reject') }}</x-ui.button></form>
                            @elseif ($removal->status->value === 'processing')
                                <form method="POST" action="{{ route('admin.inventory.removals.act', [$removal->id, 'complete']) }}">@csrf<x-ui.button type="submit" size="sm">{{ $removal->type->value === 'disposal' ? __('Mark disposed') : __('Mark shipped') }}</x-ui.button></form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $removals->links() }}
</x-layouts.panel>
