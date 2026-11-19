<x-layouts.panel :title="$voucher->label" :subtitle="__('Voucher in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Vouchers'), $voucher->label]">
    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Credit value')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $voucher->value, $voucher->currency)" />
        <x-ui.stat-card :label="__('Redeemed')" :value="$voucher->redemptions_count.' / '.$voucher->max_redemptions" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5"><span class="text-sm text-fg-secondary">{{ __('Status') }}</span><x-ui.status-badge :status="$voucher->status" class="self-start" /></div>
    </section>

    @can('gift_cards.manage')
        @if ($voucher->status === \App\Domains\GiftCards\Enums\VoucherStatus::Active)
            <x-ui.card :title="__('Void this voucher')" :subtitle="__('Stops new redemptions; credits already granted stay.')">
                <form method="POST" action="{{ route('admin.gift-cards.vouchers.void', $voucher->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.input :label="__('Reason')" name="reason" required />
                    <x-ui.button type="submit" variant="danger">{{ __('Void voucher') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    @endcan

    <x-ui.card :title="__('Redemptions')" flush>
        <x-ui.table :headers="[__('Customer'), __('Credit'), __('When (Oman)')]" :rows="$redemptions->isNotEmpty()" :empty="__('Not redeemed yet.')">
            @foreach ($redemptions as $redemption)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ app(\App\Shared\Support\UserNames::class)->name($redemption->customer_user_id) }}</td>
                    <td class="px-5 py-3">#{{ $redemption->promotional_credit_id }}</td>
                    <td class="px-5 py-3 text-xs">{{ $redemption->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $redemptions->links() }}

    @include('panels.admin._partials.transitions', ['transitions' => $voucher->transitions])
</x-layouts.panel>
