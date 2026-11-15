@php($status = $dispute->status)
@php($S = \App\Domains\Disputes\Enums\DisputeStatus::class)
<x-layouts.panel :title="__('Dispute :number', ['number' => $dispute->number])"
                 :subtitle="__('Shipment :shipment · order :order', ['shipment' => $dispute->subOrder->number, 'order' => $dispute->order->number])"
                 :breadcrumbs="[__('Orders & disputes'), __('Disputes'), $dispute->number]">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <section class="grid gap-4 sm:grid-cols-3">
                <x-ui.stat-card :label="__('Status')" :value="str_replace('_', ' ', ucfirst($status->value))" />
                <x-ui.stat-card :label="__('Resolution')" :value="$dispute->resolution ? str_replace('_', ' ', $dispute->resolution->value) : '—'" />
                <x-ui.stat-card :label="__('Refund')" :value="$dispute->refund_amount === null ? '—' : app(\App\Shared\Money\MoneyFormatter::class)->format($dispute->refund_amount, $dispute->order->currency)" />
            </section>
            @include('panels.shared.dispute-thread', ['timezone' => config('nawy.admin_timezone'), 'canReply' => $canManage, 'replyAction' => route('admin.disputes.message', $dispute->id)])
            @if ($warrantyClaims->isNotEmpty())
                <x-ui.card :title="__('Warranty claims on this shipment')">
                    <ul class="flex flex-col gap-2 text-sm">
                        @foreach ($warrantyClaims as $claim)
                            <li class="flex items-center gap-3">@can('warranty_claims.view')<a class="text-fg-link" href="{{ route('admin.warranty-claims.show', $claim->id) }}">{{ $claim->number }}</a>@else {{ $claim->number }} @endcan <x-ui.status-badge :status="$claim->status" /></li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        @if ($canManage)
            <x-ui.card :title="__('Actions')">
                <div class="flex flex-col gap-4">
                    @if (in_array($S::UnderReview, $status->allowedNext(), true))
                        <form method="POST" action="{{ route('admin.disputes.review', $dispute->id) }}">@csrf<x-ui.button type="submit">{{ __('Start review') }}</x-ui.button></form>
                    @endif
                    @if (in_array($S::Escalated, $status->allowedNext(), true))
                        <form method="POST" action="{{ route('admin.disputes.escalate', $dispute->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.input :label="__('Escalation reason')" name="reason" required />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Escalate') }}</x-ui.button></div>
                        </form>
                    @endif
                    @if (in_array($S::Resolved, $status->allowedNext(), true))
                        <form method="POST" action="{{ route('admin.disputes.resolve', $dispute->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.select :label="__('Decision')" name="resolution" :options="['favor_customer' => __('Favor customer (refund)'), 'split' => __('Split (partial refund)'), 'favor_seller' => __('Favor seller'), 'no_action' => __('No action')]" />
                            <x-ui.input :label="__('Refund amount (split; optional for favor customer)')" name="refund_amount" inputmode="decimal" />
                            <x-ui.input :label="__('Note')" name="note" />
                            <div><x-ui.button type="submit">{{ __('Resolve') }}</x-ui.button></div>
                        </form>
                    @endif
                    @if (in_array($S::Closed, $status->allowedNext(), true))
                        <form method="POST" action="{{ route('admin.disputes.close', $dispute->id) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('Close dispute') }}</x-ui.button></form>
                    @endif
                </div>
            </x-ui.card>
        @endif
    </div>
</x-layouts.panel>
