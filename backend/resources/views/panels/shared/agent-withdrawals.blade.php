@php
    /**
     * Delivery agent withdrawals review queue (admin: Nawy fleet drivers;
     * carrier panel: the company's drivers). Four-eyes: the reviewer who
     * approved cannot mark it paid.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator<\App\Domains\Delivery\Models\DeliveryWithdrawal> $withdrawals
     */
@endphp
<x-layouts.panel :title="__('Agent withdrawals')" :subtitle="__('Drivers\' wallet withdrawals to their bank accounts')" :breadcrumbs="$breadcrumbs">
    @if ($fourEyes)
        <div class="rounded-xl border border-line bg-subtle p-4 text-sm" data-four-eyes>{{ __('Four-eyes rule: a different reviewer must mark a withdrawal paid after it is approved.') }}</div>
    @endif
    @error('action')<p class="text-sm text-fg-danger">{{ $message }}</p>@enderror

    <form method="GET" class="flex flex-wrap items-end gap-3">
        @if ($panel === 'admin')
            <x-ui.select :label="__('Drivers')" name="scope" :options="['fleet' => __('Nawy fleet'), 'carrier' => __('Carrier drivers (read only)'), 'all' => __('All')]" :selected="$filters['scope'] ?? 'fleet'" />
        @endif
        <x-ui.select :label="__('Status')" name="status" :options="['' => __('All')] + collect(\App\Domains\Delivery\Enums\AgentWithdrawalStatus::cases())->mapWithKeys(fn ($status) => [$status->value => \App\Shared\Localization\Label::of($status->value)])->all()" :selected="$filters['status'] ?? ''" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Number'), __('Driver'), __('Carrier'), __('Amount'), __('Bank account'), __('Requested'), __('Status'), '']" :rows="$withdrawals->isNotEmpty()">
            @foreach ($withdrawals as $withdrawal)
                @php($payout = $withdrawal->maskedPayout())
                <tr class="border-t border-line align-top" data-withdrawal="{{ $withdrawal->number }}">
                    <td class="px-5 py-3">{{ $withdrawal->number }}</td>
                    <td class="px-5 py-3">{{ $withdrawal->agent?->name }}</td>
                    <td class="px-5 py-3">{{ $withdrawal->carrier?->name ?? __('Nawy fleet') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$withdrawal->amount" :currency="$withdrawal->currency" /></td>
                    <td class="px-5 py-3">{{ $payout['account_holder'] }}<br><span class="text-xs text-fg-secondary">{{ $payout['bank_name'] }} · ••••{{ $payout['iban_last4'] }}</span></td>
                    <td class="px-5 py-3">{{ $withdrawal->created_at?->format('j M Y H:i') }}</td>
                    <td class="px-5 py-3">
                        <x-ui.status-badge :status="$withdrawal->status" />
                        @if ($withdrawal->reason)<p class="text-xs text-fg-secondary">{{ $withdrawal->reason }}</p>@endif
                        @if ($withdrawal->reference)<p class="text-xs text-fg-secondary">{{ __('Ref :reference', ['reference' => $withdrawal->reference]) }}</p>@endif
                    </td>
                    <td class="px-5 py-3">
                        @if ($withdrawal->status->isOpen() && ($panel === 'carrier' || $withdrawal->carrier_id === null))
                            <div class="flex flex-col gap-2">
                                @if ($withdrawal->status->value === 'pending' && $canApprove)
                                    <form method="POST" action="{{ route($actionRoute, $withdrawal->id) }}">@csrf
                                        <x-ui.button type="submit" size="sm" name="action" value="approve">{{ __('Approve') }}</x-ui.button>
                                    </form>
                                @endif
                                @if ($withdrawal->status->value === 'approved' && $canPay)
                                    <form method="POST" action="{{ route($actionRoute, $withdrawal->id) }}" class="flex gap-2">@csrf
                                        <input type="hidden" name="action" value="pay">
                                        <input name="reference" required maxlength="100" placeholder="{{ __('Bank reference') }}" aria-label="{{ __('Bank reference') }}" class="h-8 w-36 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm">{{ __('Mark paid') }}</x-ui.button>
                                    </form>
                                @endif
                                @if ($canApprove)
                                    <form method="POST" action="{{ route($actionRoute, $withdrawal->id) }}" class="flex gap-2">@csrf
                                        <input type="hidden" name="action" value="reject">
                                        <input name="reason" required maxlength="255" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reason') }}" class="h-8 w-36 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $withdrawals->links() }}
</x-layouts.panel>
