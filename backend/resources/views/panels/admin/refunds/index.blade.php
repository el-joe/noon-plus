<x-layouts.panel :title="__('Refunds')" :subtitle="__('Refund approvals in :country · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Orders & disputes'), __('Refunds')]">
    @include('panels.admin.refunds._nav')

    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Waiting for approval')" :value="(string) ($counts['pending'] ?? 0)" />
        <x-ui.stat-card :label="__('Failed — retry')" :value="(string) ($counts['failed'] ?? 0)" />
        <x-ui.stat-card :label="__('Completed')" :value="(string) ($counts['completed'] ?? 0)" />
    </section>

    <form method="GET" action="{{ route('admin.refunds') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Refunds\Enums\RefundStatus::cases())->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->value)])->all()" />
        <x-ui.select :label="__('Source')" name="source" :selected="$filters['source'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Refunds\Enums\RefundSource::cases())->mapWithKeys(fn ($source) => [$source->value => str_replace('_', ' ', ucfirst($source->value))])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Refund'), __('Order'), __('Source'), __('Amount'), __('To'), __('Requested (Oman)'), __('Status')]" :rows="$refunds->isNotEmpty()" :empty="__('No refunds here.')">
            @foreach ($refunds as $refund)
                <tr class="border-t border-line" data-refund="{{ $refund->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.refunds.show', $refund->id) }}">{{ $refund->number }}</a></td>
                    <td class="px-5 py-3">{{ $refund->order?->number }}</td>
                    <td class="px-5 py-3">{{ str_replace('_', ' ', ucfirst($refund->source_type->value)) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $refund->amount" :currency="$refund->currency" /></td>
                    <td class="px-5 py-3">{{ $refund->destination === \App\Domains\Refunds\Enums\RefundDestination::Wallet ? __('Nawy Credits') : __('Original method') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $refund->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$refund->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $refunds->links() }}
</x-layouts.panel>
