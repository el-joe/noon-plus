@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
@php($show = fn (\Brick\Money\Money $money) => $fmt->format((string) $money->getAmount(), $country->currency))
@php($totals = $report['totals'])
<x-layouts.panel :title="__('Earnings')" :subtitle="__(':store · :from – :to (:zone) · amounts in :currency incl. VAT', ['store' => $vendor->store_name, 'from' => $from->format('j M Y'), 'to' => $to->format('j M Y'), 'zone' => $country->timezone, 'currency' => $country->currency])" :breadcrumbs="[__('Seller Center'), __('Finance'), __('Earnings')]">
    <x-slot:actions>
        <x-ui.button variant="outline" :href="route('partner.statements')">{{ __('Statements') }}</x-ui.button>
        <x-ui.button :href="route('partner.payouts')">{{ __('Payouts') }}</x-ui.button>
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-earnings-balances>
        <x-ui.stat-card :label="__('Pending (awaiting COD remittance)')" :value="$show($balances['pending'])" data-balance="pending" />
        <x-ui.stat-card :label="__('Spendable (next statement)')" :value="$show($balances['spendable'])" data-balance="spendable" />
        <x-ui.stat-card :label="__('Reserved (being paid)')" :value="$show($balances['reserved'])" data-balance="reserved" />
        <x-ui.stat-card :label="__('Paid out (:count statements)', ['count' => $balances['paid_out_count']])" :value="$show($balances['paid_out'])" data-balance="paid_out" />
    </section>

    <form method="GET" action="{{ route('partner.earnings') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" data-earnings-period>
        <x-ui.select :label="__('Period')" name="period" :selected="$period" :options="['this_month' => __('This month'), 'last_month' => __('Last month'), 'last_3_months' => __('Last 3 months'), 'this_year' => __('This year'), 'last_12_months' => __('Last 12 months'), 'custom' => __('Custom dates')]" />
        <x-ui.input :label="__('From (custom)')" name="from" type="date" :value="$from->toDateString()" />
        <x-ui.input :label="__('To (custom)')" name="to" type="date" :value="$to->toDateString()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Show') }}</x-ui.button></div>
    </form>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-earnings-totals>
        <x-ui.stat-card :label="__('Gross sales (:count orders)', ['count' => $totals['orders']])" :value="$show($totals['gross'])" data-total="gross" />
        <x-ui.stat-card :label="__('Commission incl. VAT')" :value="$show($totals['commission']->negated())" data-total="commission" />
        <x-ui.stat-card :label="__('Fees')" :value="$show($totals['fees']->negated())" data-total="fees" />
        <x-ui.stat-card :label="__('Refunds')" :value="$show($totals['refunds']->negated())" data-total="refunds" />
        <x-ui.stat-card :label="__('Seller coupons')" :value="$show($totals['coupons']->negated())" data-total="coupons" />
        <x-ui.stat-card :label="__('Marketing & ads')" :value="$show($totals['marketing']->negated())" data-total="marketing" />
        <x-ui.stat-card :label="__('Net earnings')" :value="$show($totals['net'])" class="lg:col-span-2" data-total="net" />
    </section>

    <x-ui.card :title="__('By month')" :subtitle="__('Orders count when their settlement is made; refunds include refunds after settlement.')" flush>
        <x-ui.table :headers="[__('Month'), __('Orders'), __('Gross sales'), __('Seller coupons'), __('Refunds'), __('Commission'), __('Fees'), __('Marketing & ads'), __('Net')]">
            @foreach ($report['months'] as $row)
                <tr class="border-t border-line" data-month="{{ $row['month'] }}">
                    <td class="px-5 py-3 font-medium whitespace-nowrap">{{ $row['label'] }}</td>
                    <td class="px-5 py-3">{{ $row['orders'] }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['gross']) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['coupons']->negated()) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['refunds']->negated()) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['commission']->negated()) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['fees']->negated()) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $show($row['marketing']->negated()) }}</td>
                    <td class="px-5 py-3 font-semibold tabular-nums" data-net>{{ $show($row['net']) }}</td>
                </tr>
            @endforeach
            <tr class="border-t border-line bg-subtle font-semibold" data-month="total">
                <td class="px-5 py-3">{{ __('Total') }}</td>
                <td class="px-5 py-3">{{ $totals['orders'] }}</td>
                @foreach (['gross', 'coupons', 'refunds', 'commission', 'fees', 'marketing'] as $column)
                    <td class="px-5 py-3 tabular-nums">{{ $show($column === 'gross' ? $totals[$column] : $totals[$column]->negated()) }}</td>
                @endforeach
                <td class="px-5 py-3 tabular-nums">{{ $show($totals['net']) }}</td>
            </tr>
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
