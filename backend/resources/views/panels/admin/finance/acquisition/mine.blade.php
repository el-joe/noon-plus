@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('My acquisition commissions')" :subtitle="__('What you earn on the sales of sellers you brought in · :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('My acquisition commissions')]">
    @include('panels.admin.finance.acquisition._nav')

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Sellers brought in')" :value="(string) $vendorsCount" />
        <x-ui.stat-card :label="__('Awaiting approval')" :value="$fmt->format((string) ($totals->get('pending') ?? '0'), $country->currency)" />
        <x-ui.stat-card :label="__('Approved, to be paid')" :value="$fmt->format((string) ($totals->get('approved') ?? '0'), $country->currency)" />
        <x-ui.stat-card :label="__('Paid')" :value="$fmt->format((string) ($totals->get('paid') ?? '0'), $country->currency)" />
    </section>

    <x-ui.card :title="__('Your referral link')" :subtitle="__('Partners who apply from this link are credited to you when approved.')">
        <input readonly value="{{ $referralLink }}" class="h-10 w-full rounded-lg border border-line bg-surface px-3 font-mono text-xs" data-referral-link onclick="this.select()">
    </x-ui.card>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Commission'), __('Month'), __('Seller'), __('Sales'), __('Rate'), __('Amount'), __('Paid'), __('Status')]" :rows="$commissions->isNotEmpty()" :empty="__('No acquisition commissions for you yet.')">
            @foreach ($commissions as $commission)
                <tr class="border-t border-line" data-my-commission="{{ $commission->id }}">
                    <td class="px-5 py-3 font-medium">{{ $commission->number }}</td>
                    <td class="px-5 py-3">{{ $commission->period_start->format('M Y') }}</td>
                    <td class="px-5 py-3">{{ $commission->vendor?->store_name }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $commission->sales_amount" :currency="$commission->currency" /></td>
                    <td class="px-5 py-3">{{ rtrim(rtrim((string) $commission->rate_percent, '0'), '.') }}%</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $commission->amount" :currency="$commission->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $commission->paid_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$commission->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $commissions->links() }}
</x-layouts.panel>
