<x-layouts.panel :title="$payment->reference" :subtitle="__('Payment in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Payments under review'), $payment->reference]">
    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Expected')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) $payment->amount, $payment->currency)" />
        <x-ui.stat-card :label="__('Captured by the gateway')" :value="app(\App\Shared\Money\MoneyFormatter::class)->format((string) ($payment->captured_amount ?? '0'), $payment->currency)" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5"><span class="text-sm text-fg-secondary">{{ __('Status') }}</span><x-ui.status-badge :status="$payment->status" class="self-start" /></div>
    </section>

    @if ($payment->status === \App\Domains\Payments\Enums\PaymentStatus::UnderReview)
        <x-ui.card :title="__('Resolve after reconciliation')" :subtitle="__('Accepting a smaller capture leaves the order short-paid; refunding sends the captured money back through the gateway.')">
            <form method="POST" action="{{ route('admin.finance.payments-review.resolve', $payment->id) }}" class="grid gap-3 sm:grid-cols-3">
                @csrf
                <x-ui.select :label="__('Resolution')" name="action" :options="['accept' => __('Accept the captured amount'), 'mark_paid' => __('Mark paid in full (reconciled)'), 'refund' => __('Refund the captured amount'), 'fail' => __('Fail the payment')]" />
                <x-ui.input :label="__('Reconciliation note / reference')" name="note" required />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Resolve') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Status history')" :subtitle="__('Times in Oman')" flush>
        <x-ui.table :headers="[__('When'), __('From'), __('To'), __('Source'), __('Reason')]" :rows="$payment->statusHistory->isNotEmpty()">
            @foreach ($payment->statusHistory as $change)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 text-xs">{{ $change->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">{{ $change->from_status instanceof \BackedEnum ? $change->from_status->value : ($change->from_status ?? '—') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$change->to_status" /></td>
                    <td class="px-5 py-3 text-xs">{{ $change->source }}</td>
                    <td class="px-5 py-3 text-xs">{{ $change->reason ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
