@php($status = $claim->status)
@php($S = \App\Domains\Warranty\Enums\WarrantyClaimStatus::class)
@php($next = $status->allowedNext())
<x-layouts.panel :title="__('Claim :number', ['number' => $claim->number])" :subtitle="__('Shipment :shipment · :item', ['shipment' => $claim->subOrder?->number, 'item' => $claim->orderItem?->title])" :breadcrumbs="[__('Orders & disputes'), __('Warranty claims'), $claim->number]">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <section class="grid gap-4 sm:grid-cols-3">
                <x-ui.stat-card :label="__('Status')" :value="str_replace('_', ' ', ucfirst($status->value))" />
                <x-ui.stat-card :label="__('Coverage')" :value="$claim->coverage === \App\Domains\Warranty\Enums\WarrantyCoverage::Extended ? __('Extended (Nawy)') : __('Standard (seller)')" />
                <x-ui.stat-card :label="__('Outcome')" :value="$claim->outcome ? ucfirst($claim->outcome->value) : '—'" />
            </section>

            <x-ui.card :title="__('Thread')" :subtitle="__('Customer, seller and Nawy · times in Oman')">
                <ol class="flex flex-col gap-3">
                    @foreach ($claim->messages as $message)
                        <li class="rounded-lg border border-line p-3 text-sm">
                            <p class="mb-1 text-xs text-fg-secondary">{{ ucfirst($message->author_type instanceof \BackedEnum ? $message->author_type->value : (string) $message->author_type) }} · {{ $message->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</p>
                            <p class="whitespace-pre-line">{{ $message->body }}</p>
                        </li>
                    @endforeach
                </ol>
                @if ($canManage && $status !== $S::Closed)
                    <form method="POST" action="{{ route('admin.warranty-claims.message', $claim->id) }}" class="mt-4 flex flex-col gap-2">
                        @csrf
                        <x-ui.textarea :label="__('Reply')" name="body" required />
                        <div><x-ui.button type="submit" variant="outline">{{ __('Send') }}</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Disputes on this shipment')" flush>
                <x-ui.table :headers="[__('Dispute'), __('Reason'), __('Status')]" :rows="$disputes->isNotEmpty()" :empty="__('No dispute on this shipment.')">
                    @foreach ($disputes as $dispute)
                        <tr class="border-t border-line">
                            <td class="px-5 py-3">@can('disputes.view')<a class="text-fg-link" href="{{ route('admin.disputes.show', $dispute->id) }}">{{ $dispute->number }}</a>@else {{ $dispute->number }} @endcan</td>
                            <td class="px-5 py-3">{{ str_replace('_', ' ', $dispute->reason->value) }}</td>
                            <td class="px-5 py-3"><x-ui.status-badge :status="$dispute->status" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @include('panels.admin._partials.transitions', ['transitions' => $claim->transitions])
        </div>

        @if ($canManage)
            <x-ui.card :title="__('Actions')">
                <div class="flex flex-col gap-4">
                    @if (in_array($S::UnderReview, $next, true))
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'review']) }}">@csrf<x-ui.button type="submit">{{ __('Start review') }}</x-ui.button></form>
                    @endif
                    @if (in_array($S::Approved, $next, true))
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'approve']) }}">@csrf<x-ui.button type="submit">{{ __('Approve claim') }}</x-ui.button></form>
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'reject']) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.input :label="__('Rejection reason')" name="reason" required />
                            <div><x-ui.button type="submit" variant="danger">{{ __('Reject claim') }}</x-ui.button></div>
                        </form>
                    @endif
                    @if (in_array($S::InRepair, $next, true))
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'fulfil']) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.select :label="__('Outcome')" name="outcome" :options="['repair' => __('Repair'), 'replace' => __('Replace'), 'refund' => __('Refund')]" />
                            <x-ui.input :label="__('Refund amount (default: one unit)')" name="refund_amount" inputmode="decimal" />
                            <div><x-ui.button type="submit">{{ __('Fulfil') }}</x-ui.button></div>
                        </form>
                    @endif
                    @if (in_array($S::Resolved, $next, true))
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'resolve']) }}">@csrf<x-ui.button type="submit">{{ __('Mark resolved') }}</x-ui.button></form>
                    @endif
                    @if (in_array($S::Closed, $next, true))
                        <form method="POST" action="{{ route('admin.warranty-claims.act', [$claim->id, 'close']) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('Close claim') }}</x-ui.button></form>
                    @endif
                    @if ($next === [])
                        <p class="text-sm text-fg-secondary">{{ __('No further action.') }}</p>
                    @endif
                </div>
            </x-ui.card>
        @endif
    </div>
</x-layouts.panel>
