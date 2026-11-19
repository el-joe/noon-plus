@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
@php($status = $payout->status)
<x-layouts.panel :title="__('Statement :number', ['number' => $payout->number])"
                 :subtitle="__(':vendor · :from – :to · :schedule', ['vendor' => $payout->vendor->store_name, 'from' => $payout->period_start->format('j M'), 'to' => $payout->period_end->format('j M Y'), 'schedule' => $payout->schedule->value])"
                 :breadcrumbs="[__('Finance'), __('Payouts'), $payout->number]">
    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Gross sales')" :value="$fmt->format((string) $statement['gross']->getAmount(), $payout->currency)" :delta="trans_choice(':count order|:count orders', $statement['orders'])" />
        <x-ui.stat-card :label="__('Nawy fees')" :value="$fmt->format((string) $statement['fees']->getAmount(), $payout->currency)" trend="down" />
        <x-ui.stat-card :label="__('Adjustments')" :value="$fmt->format((string) $statement['adjustments']->getAmount(), $payout->currency)" />
        <x-ui.stat-card :label="__('Net payout')" :value="$fmt->format((string) $statement['net']->getAmount(), $payout->currency)" />
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            @include('panels.shared.statement-lines')
        </div>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Maker-checker')">
                <dl class="mb-4 grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-fg-secondary">{{ __('Status') }}</dt><dd class="text-end"><x-ui.status-badge :status="$status" /></dd>
                    <dt class="text-fg-secondary">{{ __('Prepared by') }}</dt><dd class="text-end">{{ $payout->generated_by_user_id ? app(\App\Shared\Support\UserNames::class)->name($payout->generated_by_user_id) : __('System') }}</dd>
                    <dt class="text-fg-secondary">{{ __('Approved') }}</dt><dd class="text-end">{{ $payout->approved_at?->setTimezone(config('nawy.admin_timezone'))->format('j M H:i').($payout->approved_at ? ' '.__('Oman') : '') ?: '—' }}</dd>
                    @if ($payout->hold_reason)<dt class="text-fg-secondary">{{ __('Hold') }}</dt><dd class="text-end">{{ $payout->hold_reason }}</dd>@endif
                    @if ($payout->failure_reason)<dt class="text-fg-secondary">{{ __('Failure') }}</dt><dd class="text-end">{{ $payout->failure_reason }}</dd>@endif
                    @if ($payout->bank_reference)<dt class="text-fg-secondary">{{ __('Bank reference') }}</dt><dd class="text-end">{{ $payout->bank_reference }}</dd>@endif
                </dl>
                <div class="flex flex-col gap-3">
                    @can('payouts.approve')
                        @if ($status === \App\Domains\Finance\Enums\PayoutStatus::Pending)
                            <form method="POST" action="{{ route('admin.finance.payouts.approve', $payout->id) }}">@csrf<x-ui.button type="submit">{{ __('Approve') }}</x-ui.button></form>
                            <form method="POST" action="{{ route('admin.finance.payouts.recalculate', $payout->id) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('Recalculate') }}</x-ui.button></form>
                            <form method="POST" action="{{ route('admin.finance.payouts.hold', $payout->id) }}" class="flex flex-col gap-2">
                                @csrf
                                <x-ui.input :label="__('Hold reason')" name="reason" required />
                                <div><x-ui.button type="submit" variant="outline">{{ __('Put on hold') }}</x-ui.button></div>
                            </form>
                        @elseif ($status === \App\Domains\Finance\Enums\PayoutStatus::OnHold)
                            <form method="POST" action="{{ route('admin.finance.payouts.release', $payout->id) }}">@csrf<x-ui.button type="submit">{{ __('Release hold') }}</x-ui.button></form>
                        @endif
                    @endcan
                    @can('payouts.process')
                        @if (in_array($status, [\App\Domains\Finance\Enums\PayoutStatus::Approved, \App\Domains\Finance\Enums\PayoutStatus::Failed], true))
                            <form method="POST" action="{{ route('admin.finance.payouts.process', $payout->id) }}">@csrf<x-ui.button type="submit">{{ __('Send bank transfer') }}</x-ui.button></form>
                        @elseif ($status === \App\Domains\Finance\Enums\PayoutStatus::Processing)
                            <form method="POST" action="{{ route('admin.finance.payouts.complete', $payout->id) }}" class="flex flex-col gap-2">
                                @csrf
                                <x-ui.input :label="__('Bank reference')" name="bank_reference" required />
                                <div><x-ui.button type="submit">{{ __('Mark paid') }}</x-ui.button></div>
                            </form>
                            <form method="POST" action="{{ route('admin.finance.payouts.fail', $payout->id) }}" class="flex flex-col gap-2">
                                @csrf
                                <x-ui.input :label="__('Failure reason')" name="reason" :id="'fail-reason'" required />
                                <div><x-ui.button type="submit" variant="danger">{{ __('Mark failed') }}</x-ui.button></div>
                            </form>
                        @endif
                    @endcan
                </div>
            </x-ui.card>

            <x-ui.state-timeline :title="__('Audit log')" :subtitle="__('Oman time')"
                                 :steps="$payout->transitions->values()->map(fn ($step, $index) => ['state' => $index === $payout->transitions->count() - 1 ? 'current' : 'done', 'label' => str_replace('_', ' ', ucfirst($step->to_status)).($step->reason ? ' — '.$step->reason : ''), 'meta' => $step->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M, H:i').' · '.$step->actor_type.($step->actor_user_id ? ' '.app(\App\Shared\Support\UserNames::class)->name($step->actor_user_id) : '')])->all()" />
        </div>
    </div>
</x-layouts.panel>
