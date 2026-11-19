@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
@php($metrics = $report->metrics ?? [])
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Key figures') }}">
    <x-ui.stat-card :label="__('Attributed sales')" :value="$fmt->format((string) $report->attributed_sales, $report->currency)" :delta="trans_choice(':count order|:count orders', $report->orders_count)" />
    <x-ui.stat-card :label="__('Total earnings')" :value="$fmt->format((string) $report->total_earnings, $report->currency)" />
    <x-ui.stat-card :label="__('Conversions')" :value="$report->conversions_count" :delta="trans_choice(':count unit sold|:count units sold', $report->units_sold)" />
    <x-ui.stat-card :label="__('Referral clicks')" :value="$report->referral_clicks" />
</section>

<div class="grid gap-4 xl:grid-cols-2">
    <x-ui.card :title="__('Earnings')">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-fg-secondary">{{ __('Net commission') }}</dt><dd><x-ui.money :amount="(string) $report->commission_net" :currency="$report->currency" /></dd></div>
            <div><dt class="text-fg-secondary">{{ __('Bonuses') }}</dt><dd><x-ui.money :amount="(string) $report->bonus_total" :currency="$report->currency" /></dd></div>
            <div><dt class="text-fg-secondary">{{ __('Fixed fees') }}</dt><dd><x-ui.money :amount="(string) $report->fixed_fees" :currency="$report->currency" /></dd></div>
            <div><dt class="text-fg-secondary">{{ __('Reversed commission') }}</dt><dd><x-ui.money :amount="(string) ($metrics['reversed_commission'] ?? '0')" :currency="$report->currency" /></dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Campaigns')">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-fg-secondary">{{ __('Invitations received') }}</dt><dd>{{ $metrics['campaigns']['invitations'] ?? 0 }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Invitations accepted') }}</dt><dd>{{ $metrics['campaigns']['accepted'] ?? 0 }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Active campaigns') }}</dt><dd>{{ $metrics['campaigns']['active'] ?? 0 }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Content approved') }}</dt><dd>{{ $metrics['campaigns']['content_approved'] ?? 0 }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Top products')" flush>
        <x-ui.table :headers="[__('Product'), __('Units'), __('Sales')]" :rows="! empty($metrics['top_products'])" :empty="__('No attributed sales this month.')">
            @foreach ($metrics['top_products'] ?? [] as $product)
                <tr class="border-t border-line" data-top-product="{{ $product['listing_id'] }}">
                    <td class="px-5 py-3">{{ $product['title'] }}</td>
                    <td class="px-5 py-3">{{ $product['units'] }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $product['sales']" :currency="$report->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Conversions by status')" flush>
        <x-ui.table :headers="[__('Status'), __('Conversions')]" :rows="! empty($metrics['conversions_by_status'])" :empty="__('No conversions this month.')">
            @foreach ($metrics['conversions_by_status'] ?? [] as $status => $count)
                <tr class="border-t border-line"><td class="px-5 py-3"><x-ui.status-badge :status="$status" /></td><td class="px-5 py-3">{{ $count }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>

<x-ui.card :title="__('Monthly obligations')" flush>
    <x-ui.table :headers="[__('Policy'), __('Required'), __('Completed'), __('Status')]" :rows="! empty($metrics['cross_promotion'])" :empty="__('No cross-promotion obligation this month.')">
        @foreach ($metrics['cross_promotion'] ?? [] as $obligation)
            <tr class="border-t border-line"><td class="px-5 py-3">{{ $obligation['policy'] }}</td><td class="px-5 py-3">{{ $obligation['required'] }}</td><td class="px-5 py-3">{{ $obligation['completed'] }}</td><td class="px-5 py-3"><x-ui.status-badge :status="$obligation['status']" /></td></tr>
        @endforeach
    </x-ui.table>
</x-ui.card>
