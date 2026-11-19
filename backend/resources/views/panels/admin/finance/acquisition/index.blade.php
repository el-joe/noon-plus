@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="__('Acquisition commissions')" :subtitle="__('Monthly commissions of admins who brought vendors in · :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Acquisition commissions')]">
    @include('panels.admin.finance.acquisition._nav')

    <section class="grid gap-4 sm:grid-cols-4">
        @foreach (\App\Domains\Finance\Enums\AcquisitionCommissionStatus::cases() as $status)
            <x-ui.stat-card :label="\App\Shared\Localization\Label::of($status)" :value="$fmt->format((string) ($totals->get($status->value)?->total ?? '0'), $country->currency)" :delta="trans_choice(':count commission|:count commissions', (int) ($totals->get($status->value)?->rows_count ?? 0))" />
        @endforeach
    </section>

    <form method="GET" action="{{ route('admin.finance.acquisition-commissions') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Finance\Enums\AcquisitionCommissionStatus::cases())->mapWithKeys(fn ($status) => [$status->value => \App\Shared\Localization\Label::of($status)])->all()" />
        <x-ui.select :label="__('Acquirer')" name="acquirer" :selected="$filters['acquirer'] ?? ''" :placeholder="__('All')" :options="$acquirers->pluck('name', 'id')->all()" />
        <x-ui.input :label="__('Month')" name="month" type="month" :value="$filters['month'] ?? ''" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Commission'), __('Month'), __('Seller'), __('Acquirer'), __('Sales'), __('Rate'), __('Amount'), __('Status')]" :rows="$commissions->isNotEmpty()" :empty="__('No acquisition commissions yet.')">
            @foreach ($commissions as $commission)
                <tr class="border-t border-line" data-commission="{{ $commission->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.acquisition-commissions.show', $commission->id) }}">{{ $commission->number }}</a></td>
                    <td class="px-5 py-3">{{ $commission->period_start->format('M Y') }}</td>
                    <td class="px-5 py-3">{{ $commission->vendor?->store_name }}</td>
                    <td class="px-5 py-3">{{ $users->get($commission->acquirer_user_id) ?? '#'.$commission->acquirer_user_id }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $commission->sales_amount" :currency="$commission->currency" /></td>
                    <td class="px-5 py-3">{{ rtrim(rtrim((string) $commission->rate_percent, '0'), '.') }}%</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $commission->amount" :currency="$commission->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$commission->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $commissions->links() }}

    @can('acquisition_commissions.manage')
        <x-ui.card :title="__('Compute a month')" :subtitle="__('Runs automatically on the 1st at 02:00 (Oman) for the previous month; already computed sellers are skipped.')">
            <form method="POST" action="{{ route('admin.finance.acquisition-commissions.generate') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-ui.input :label="__('Month')" name="month" type="month" :value="now()->subMonth()->format('Y-m')" required />
                <x-ui.button type="submit" variant="outline">{{ __('Compute') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
