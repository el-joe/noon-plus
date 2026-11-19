<x-layouts.panel :title="$commission->number" :subtitle="__(':store · :month', ['store' => $commission->vendor?->store_name, 'month' => $commission->period_start->format('F Y')])" :breadcrumbs="[__('Finance'), __('Acquisition commissions'), $commission->number]">
    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Commission')">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Acquirer') }}</dt><dd>{{ $acquirer?->name ?? '#'.$commission->acquirer_user_id }} · {{ $acquirer?->email }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$commission->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Period') }}</dt><dd>{{ $commission->period_start->format('j M Y') }} – {{ $commission->period_end->format('j M Y') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Rule') }}</dt><dd>{{ $commission->rule?->name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Settled sales') }}</dt><dd><x-ui.money :amount="(string) $commission->sales_amount" :currency="$commission->currency" /> · {{ trans_choice(':count order|:count orders', $commission->sub_orders_count) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Rate') }}</dt><dd>{{ rtrim(rtrim((string) $commission->rate_percent, '0'), '.') }}%</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Amount') }}</dt><dd class="font-semibold"><x-ui.money :amount="(string) $commission->amount" :currency="$commission->currency" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Bank reference') }}</dt><dd>{{ $commission->bank_reference ?? '—' }}</dd></div>
                @if ($commission->rejection_reason)
                    <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Rejection reason') }}</dt><dd>{{ $commission->rejection_reason }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        @can('acquisition_commissions.manage')
            <x-ui.card :title="__('Actions')">
                <div class="flex flex-col gap-3">
                    @if ($commission->status === \App\Domains\Finance\Enums\AcquisitionCommissionStatus::Pending)
                        <form method="POST" action="{{ route('admin.finance.acquisition-commissions.approve', $commission->id) }}">@csrf<x-ui.button type="submit">{{ __('Approve') }}</x-ui.button></form>
                    @endif
                    @if ($commission->status === \App\Domains\Finance\Enums\AcquisitionCommissionStatus::Approved)
                        <form method="POST" action="{{ route('admin.finance.acquisition-commissions.pay', $commission->id) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-ui.input :label="__('Bank reference')" name="bank_reference" required />
                            <x-ui.button type="submit">{{ __('Mark paid') }}</x-ui.button>
                        </form>
                    @endif
                    @if (in_array($commission->status, [\App\Domains\Finance\Enums\AcquisitionCommissionStatus::Pending, \App\Domains\Finance\Enums\AcquisitionCommissionStatus::Approved], true))
                        <form method="POST" action="{{ route('admin.finance.acquisition-commissions.reject', $commission->id) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-ui.input :label="__('Reason')" name="reason" required />
                            <x-ui.button type="submit" variant="danger">{{ __('Reject') }}</x-ui.button>
                        </form>
                    @else
                        <p class="text-sm text-fg-secondary">{{ __('No actions left for this commission.') }}</p>
                    @endif
                </div>
            </x-ui.card>
        @endcan
    </section>

    @include('panels.admin._partials.transitions', ['transitions' => $commission->transitions])
</x-layouts.panel>
