{{-- Return detail shared by admin and Seller Center: items, review, logistics and inspection (SRS §7.4). --}}
@php($currency = $country->currency)
<div class="grid gap-6 xl:grid-cols-3">
    <div class="flex flex-col gap-6 xl:col-span-2">
        <section class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card :label="__('Status')" :value="str_replace('_', ' ', ucfirst($return->status->value))" />
            <x-ui.stat-card :label="__('Method')" :value="$return->method === \App\Domains\Returns\Enums\ReturnMethod::Pickup ? __('Pickup') : __('Drop-off')" />
            <x-ui.stat-card :label="__('Refund')" :value="$return->refund_total === null ? '—' : app(\App\Shared\Money\MoneyFormatter::class)->format($return->refund_total, $currency)" />
        </section>

        <x-ui.card :title="__('Returned items')" :subtitle="__('Reason: :reason', ['reason' => str_replace('_', ' ', $return->reason->value)])" flush>
            <x-ui.table :headers="[__('Item'), __('Qty'), __('Paid'), __('Outcome'), __('Restock'), __('Refund')]">
                @foreach ($return->items as $item)
                    <tr class="border-t border-line" data-return-item="{{ $item->id }}">
                        <td class="px-5 py-3"><span class="font-medium">{{ $item->orderItem->title }}</span><span class="block text-xs text-fg-secondary">{{ $item->orderItem->sku }}</span></td>
                        <td class="px-5 py-3">{{ $item->quantity }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="$item->paid_amount" :currency="$currency" /></td>
                        <td class="px-5 py-3">{{ $item->outcome ? str_replace('_', ' ', $item->outcome->value) : '—' }}</td>
                        <td class="px-5 py-3">{{ $item->outcome ? ($item->restock ? __('Yes') : __('No')) : '—' }}</td>
                        <td class="px-5 py-3">@if ($item->refund_amount !== null)<x-ui.money :amount="$item->refund_amount" :currency="$currency" />@else — @endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($return->comment)
                <p class="px-5 py-3 text-sm text-fg-secondary">“{{ $return->comment }}”</p>
            @endif
            @if ($return->photos)
                <div class="flex flex-wrap gap-2 px-5 pb-4">
                    @foreach (app(\App\Domains\Returns\Support\ReturnPhotos::class)->urls($return) as $photoUrl)
                        <a href="{{ $photoUrl }}" class="text-xs text-fg-link" target="_blank" rel="noopener">{{ __('Photo :n', ['n' => $loop->iteration]) }}</a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        @if ($canManage && $return->status === \App\Domains\Returns\Enums\ReturnStatus::Inspecting || $canManage && $return->status === \App\Domains\Returns\Enums\ReturnStatus::Received)
            <x-ui.card :title="__('Inspection')" :subtitle="__('Good or damaged in transit → full refund. Damaged by the customer → partial or no refund.')">
                <form method="POST" action="{{ route($routePrefix.'.inspect', $return->id) }}" class="flex flex-col gap-4">
                    @csrf
                    @foreach ($return->items as $item)
                        <fieldset class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-3">
                            <legend class="px-1 text-sm font-medium">{{ $item->orderItem->title }} × {{ $item->quantity }}</legend>
                            <x-ui.select :label="__('Outcome')" name="items[{{ $item->id }}][outcome]" :id="'outcome-'.$item->id"
                                         :options="['good' => __('Good condition'), 'customer_damaged' => __('Damaged by customer'), 'transit_damaged' => __('Damaged in transit')]" />
                            <x-ui.input :label="__('Partial refund (customer damage)')" name="items[{{ $item->id }}][refund_amount]" :id="'refund-'.$item->id" inputmode="decimal" />
                            <x-ui.checkbox :label="__('Put back in stock')" name="items[{{ $item->id }}][restock]" :id="'restock-'.$item->id" />
                        </fieldset>
                    @endforeach
                    <div><x-ui.button type="submit">{{ __('Save inspection and refund') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <div class="flex flex-col gap-6">
        <x-ui.state-timeline :title="__('Return :number', ['number' => $return->number])" :subtitle="__('Every step is logged with the actor')"
                             :steps="$return->transitions->values()->map(fn ($step, $index) => ['state' => $index === $return->transitions->count() - 1 ? 'current' : 'done', 'label' => str_replace('_', ' ', ucfirst($step->to_status)), 'meta' => $step->created_at->setTimezone($country->timezone)->format('j M, H:i').' · '.$step->actor_type])->all()" />

        {{-- BT-VEN-010: the Actions card only when there is something to do (none once completed, rejected or closed). --}}
        @php($advanceTo = collect($return->status->allowedNext())
            ->filter(fn ($next) => in_array($next, [\App\Domains\Returns\Enums\ReturnStatus::AwaitingPickup, \App\Domains\Returns\Enums\ReturnStatus::InTransit, \App\Domains\Returns\Enums\ReturnStatus::Received], true))
            ->reject(fn ($next) => $next === \App\Domains\Returns\Enums\ReturnStatus::AwaitingPickup && $return->method !== \App\Domains\Returns\Enums\ReturnMethod::Pickup)
            ->values())
        @if ($canManage && ($return->status === \App\Domains\Returns\Enums\ReturnStatus::Requested || $advanceTo->isNotEmpty()))
            <x-ui.card :title="__('Actions')" data-return-actions>
                <div class="flex flex-col gap-3">
                    @if ($return->status === \App\Domains\Returns\Enums\ReturnStatus::Requested)
                        <form method="POST" action="{{ route($routePrefix.'.approve', $return->id) }}">@csrf<x-ui.button type="submit">{{ __('Approve return') }}</x-ui.button></form>
                        <form method="POST" action="{{ route($routePrefix.'.reject', $return->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.textarea :label="__('Rejection reason')" name="reason" rows="2" required />
                            <div><x-ui.button type="submit" variant="danger">{{ __('Reject') }}</x-ui.button></div>
                        </form>
                    @endif
                    @foreach ($advanceTo as $next)
                        <form method="POST" action="{{ route($routePrefix.'.advance', $return->id) }}">
                            @csrf
                            <input type="hidden" name="to" value="{{ $next->value }}">
                            <x-ui.button type="submit" variant="outline">{{ __('Mark :status', ['status' => str_replace('_', ' ', $next->value)]) }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
