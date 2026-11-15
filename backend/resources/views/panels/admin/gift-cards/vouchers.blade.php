<x-layouts.panel :title="__('Vouchers')" :subtitle="__('Codes worth promotional Nawy Credits in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Marketing'), __('Gift cards & vouchers'), __('Vouchers')]">
    @include('panels.admin.gift-cards._nav')

    <form method="GET" action="{{ route('admin.gift-cards.vouchers') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Label')" name="q" :value="$filters['q'] ?? ''" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\GiftCards\Enums\VoucherStatus::cases())->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->value)])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Voucher'), __('Value'), __('Redeemed'), __('Ends (Oman)'), __('Status')]" :rows="$vouchers->isNotEmpty()" :empty="__('No vouchers yet.')">
            @foreach ($vouchers as $voucher)
                <tr class="border-t border-line" data-voucher="{{ $voucher->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.gift-cards.vouchers.show', $voucher->id) }}">{{ $voucher->label }}</a></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $voucher->value" :currency="$voucher->currency" /></td>
                    <td class="px-5 py-3">{{ $voucher->redemptions_count }} / {{ $voucher->max_redemptions }}</td>
                    <td class="px-5 py-3 text-xs">{{ $voucher->expires_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$voucher->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $vouchers->links() }}

    @can('gift_cards.manage')
        <x-ui.card :title="__('Create a voucher')">
            <form method="POST" action="{{ route('admin.gift-cards.vouchers.store') }}" class="grid gap-3 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('Code')" name="code" required />
                <x-ui.input :label="__('Label')" name="label" required />
                <x-ui.input :label="__('Credit value (:currency)', ['currency' => $country->currency])" name="value" inputmode="decimal" required />
                <x-ui.input :label="__('Max redemptions')" name="max_redemptions" type="number" required />
                <x-ui.input :label="__('Credits expire after (days)')" name="credit_expiry_days" type="number" value="30" required />
                <x-ui.datetime-input :label="__('Voucher ends')" name="expires_at" :country="$country" required />
                <div><x-ui.button type="submit">{{ __('Create voucher') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
