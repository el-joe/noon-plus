<x-layouts.panel :title="__('Earnings')" :subtitle="__('Per-stop pay · last 7 days (:tz)', ['tz' => $currentCountry->timezone])" :breadcrumbs="[__('Money'), __('Earnings')]">
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Last 7 days') }}</span>
            <span class="text-2xl font-bold" data-earnings-total>@if ($total !== null)<x-ui.money :amount="$total" :currency="$currentCountry->currency" />@else — @endif</span>
        </div>
        <x-ui.stat-card :label="__('Stops completed')" :value="$stops" />
        <x-ui.stat-card :label="__('Failed attempts')" :value="$failedTotal" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('By day')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Day'), __('Stops'), __('Failed'), __('Earned')]">
                @foreach ($days as $day)
                    <tr class="border-t border-line" data-day="{{ $day['day']->toDateString() }}">
                        <td class="px-5 py-3">{{ $day['day']->format('D j M') }}</td>
                        <td class="px-5 py-3">{{ $day['delivered'] }}</td>
                        <td class="px-5 py-3">{{ $day['failed'] }}</td>
                        <td class="px-5 py-3">@if ($day['earned'] !== null)<x-ui.money :amount="$day['earned']" :currency="$currentCountry->currency" />@else — @endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card :title="__('Pay rates')">
            @if ($perStop === null)
                <p class="text-sm text-fg-secondary">{{ __('Pay rates are not set for this country yet. Your stops are counted and will be paid at the agreed rate.') }}</p>
            @else
                <dl class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between"><dt>{{ __('Delivered stop') }}</dt><dd><x-ui.money :amount="(string) $perStop" :currency="$currentCountry->currency" /></dd></div>
                    <div class="flex justify-between"><dt>{{ __('Failed attempt') }}</dt><dd><x-ui.money :amount="(string) ($perFailed ?? '0')" :currency="$currentCountry->currency" /></dd></div>
                </dl>
            @endif
            <p class="mt-3 text-xs text-fg-secondary">{{ $agent->carrier === null ? __('Nawy fleet issues your payslip after each pay period and credits your wallet.') : __('Your payslip is issued after each pay period; :carrier approves and pays your withdrawals.', ['carrier' => $agent->carrier->name]) }}</p>
        </x-ui.card>
    </div>

    <div class="grid gap-4 sm:grid-cols-3" data-wallet>
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Available to withdraw') }}</span>
            <span class="text-2xl font-bold" data-wallet-available><x-ui.money :amount="(string) $wallet['available']->getAmount()" :currency="$currentCountry->currency" /></span>
        </div>
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Withdrawal in progress') }}</span>
            <span class="text-2xl font-bold"><x-ui.money :amount="(string) $wallet['reserved']->getAmount()" :currency="$currentCountry->currency" /></span>
        </div>
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('Paid out') }}</span>
            <span class="text-2xl font-bold"><x-ui.money :amount="(string) $wallet['paid_out']->getAmount()" :currency="$currentCountry->currency" /></span>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Payslips')" flush class="lg:col-span-2">
            <x-ui.table :headers="[__('Payslip'), __('Period'), __('Stops'), __('Failed'), __('Total'), '']" :rows="$payslips->isNotEmpty()">
                @foreach ($payslips as $payslip)
                    <tr class="border-t border-line" data-payslip="{{ $payslip->number }}">
                        <td class="px-5 py-3">{{ $payslip->number }}</td>
                        <td class="px-5 py-3">{{ $payslip->period_start->format('j M') }} – {{ $payslip->period_end->format('j M Y') }}</td>
                        <td class="px-5 py-3">{{ $payslip->delivered }}</td>
                        <td class="px-5 py-3">{{ $payslip->failed }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="$payslip->total" :currency="$payslip->currency" /></td>
                        <td class="px-5 py-3 text-end">
                            <a class="text-sm text-fg-link underline" href="{{ route('delivery.payslips.show', $payslip->number) }}" target="_blank">{{ __('View') }}</a>
                            · <a class="text-sm text-fg-link underline" href="{{ route('delivery.payslips.pdf', $payslip->number) }}">{{ __('PDF') }}</a>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($payslips->isEmpty())
                <p class="px-5 py-4 text-sm text-fg-secondary">{{ __('Your first payslip appears the day after your first pay period with deliveries ends.') }}</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Withdraw to bank')">
            @if ($openWithdrawal !== null)
                <p class="text-sm text-fg-secondary">{{ __('Withdrawal :number is in progress. You can request another once it is paid.', ['number' => $openWithdrawal->number]) }}</p>
            @elseif ($wallet['available']->isLessThan($minimum) || ! $wallet['available']->isPositive())
                <p class="text-sm text-fg-secondary">{{ __('You can withdraw once your available balance reaches :amount.', ['amount' => $minimum->getAmount().' '.$currentCountry->currency]) }}</p>
            @else
                <form method="POST" action="{{ route('delivery.withdrawals.store') }}" class="flex flex-col gap-3" data-withdraw-form>
                    @csrf
                    <x-ui.input :label="__('Amount (:currency)', ['currency' => $currentCountry->currency])" name="amount" inputmode="decimal" :value="(string) $wallet['available']->getAmount()" :helper="__('Minimum :amount', ['amount' => $minimum->getAmount().' '.$currentCountry->currency])" required />
                    <x-ui.input :label="__('Account holder')" name="account_holder" :value="$lastPayout['account_holder'] ?? $agent->name" required />
                    <x-ui.input :label="__('Bank name')" name="bank_name" :value="$lastPayout['bank_name'] ?? ''" required />
                    <x-ui.input :label="__('IBAN')" name="iban" dir="ltr" required :helper="isset($lastPayout['iban_last4']) ? __('Last used IBAN ends in :last4', ['last4' => $lastPayout['iban_last4']]) : null" />
                    @error('action')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                    <x-ui.button type="submit">{{ __('Request withdrawal') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Withdrawals')" flush>
        <x-ui.table :headers="[__('Number'), __('Amount'), __('Bank'), __('Requested'), __('Status'), '']" :rows="$withdrawals->isNotEmpty()">
            @foreach ($withdrawals as $withdrawal)
                <tr class="border-t border-line" data-withdrawal="{{ $withdrawal->number }}">
                    <td class="px-5 py-3">{{ $withdrawal->number }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$withdrawal->amount" :currency="$withdrawal->currency" /></td>
                    <td class="px-5 py-3">{{ $withdrawal->maskedPayout()['bank_name'] }} · ••••{{ $withdrawal->maskedPayout()['iban_last4'] }}</td>
                    <td class="px-5 py-3">{{ $withdrawal->created_at?->setTimezone($currentCountry->timezone)->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$withdrawal->status" />@if ($withdrawal->reason)<p class="text-xs text-fg-secondary">{{ $withdrawal->reason }}</p>@endif @if ($withdrawal->reference)<p class="text-xs text-fg-secondary">{{ __('Ref :reference', ['reference' => $withdrawal->reference]) }}</p>@endif</td>
                    <td class="px-5 py-3 text-end">
                        @if ($withdrawal->status->value === 'pending')
                            <form method="POST" action="{{ route('delivery.withdrawals.cancel', $withdrawal->number) }}">@csrf
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Cancel') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
