<x-layouts.panel :title="__('Product requests')" :subtitle="__('Products stores and marketers asked us to add to the catalogue')" :breadcrumbs="[__('Commerce'), __('Product requests')]">
    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach (\App\Domains\Catalog\Enums\ProductRequestStatus::cases() as $case)
            <x-ui.button size="sm" :variant="$status === $case->value ? 'primary' : 'outline'" :href="route('admin.product-requests', ['status' => $case->value])">
                {{ __(\App\Shared\Localization\Label::key($case)) }} ({{ $counts[$case->value] ?? 0 }})
            </x-ui.button>
        @endforeach
    </div>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Request'), __('Product'), __('Barcode (GTIN)'), __('From'), __('Sent (Oman)'), __('Status')]" :rows="$requests->isNotEmpty()" :empty="__('No requests here.')">
            @foreach ($requests as $row)
                <tr class="border-t border-line" data-product-request="{{ $row->number }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.product-requests.show', $row->id) }}">{{ $row->number }}</a></td>
                    <td class="px-5 py-3">{{ $row->title_en }}<span class="block text-xs text-fg-secondary">{{ $row->brand_name }}</span></td>
                    <td class="px-5 py-3 text-xs">{{ $row->gtin ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row->vendor?->store_name ?? __('Marketer #:id', ['id' => $row->marketer_profile_id]) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$row->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $requests->links() }}
</x-layouts.panel>
