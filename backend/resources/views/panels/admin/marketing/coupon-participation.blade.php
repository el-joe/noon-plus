<x-layouts.panel :title="__('Coupon participation')" :subtitle="__('Invite vendors and marketers to join a platform coupon for a fee')" :breadcrumbs="[__('Growth'), __('Coupon participation')]">
    <x-ui.card :title="__('New invitation')">
        <form method="POST" action="{{ route('admin.marketing.coupon-participation.store') }}" class="grid gap-3 sm:grid-cols-3">
            @csrf
            <x-ui.select :label="__('Platform coupon')" name="coupon_id" :options="$coupons->mapWithKeys(fn ($coupon) => [$coupon->id => $coupon->code])->all()" />
            <x-ui.input :label="__('Title')" name="title" required />
            <x-ui.input :label="__('Minimum fee')" name="min_fee" type="number" step="any" min="0" required />
            <x-ui.input :label="__('Capacity')" name="capacity" type="number" required />
            <x-ui.datetime-input :label="__('Closes at')" name="closes_at" :country="$country" required />
            <label class="flex items-end gap-2 text-sm"><input type="checkbox" name="open" value="1" checked> {{ __('Open now') }}</label>
            <div><x-ui.button type="submit">{{ __('Save invitation') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    @foreach ($invitations as $invitation)
        <x-ui.card :title="$invitation->title.' · '.$invitation->coupon->code" :subtitle="__('Min fee :fee · capacity :cap · closes :at', ['fee' => number_format((float) $invitation->min_fee, 2), 'cap' => $invitation->capacity, 'at' => $invitation->closes_at->format('j M H:i')])" flush>
            <div class="px-5 pt-3"><x-ui.status-badge :status="$invitation->status" /></div>
            <x-ui.table :headers="[__('Participant'), __('Offered fee'), __('Payment'), __('Status'), __('Decision')]" :rows="$invitation->requests->isNotEmpty()" :empty="__('No requests yet.')">
                @foreach ($invitation->requests as $participation)
                    <tr class="border-t border-line" data-participation="{{ $participation->id }}">
                        <td class="px-5 py-3">{{ $participation->participant_type }} #{{ $participation->participant_id }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="$participation->offered_fee" :currency="$invitation->currency" /></td>
                        <td class="px-5 py-3">{{ str_replace('_', ' ', $participation->payment_method) }}</td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$participation->status" /></td>
                        <td class="px-5 py-3 flex flex-wrap gap-2">
                            @php($options = match ($participation->status->value) { 'requested' => ['approve' => __('Approve'), 'reject' => __('Reject')], 'approved_pending_payment' => ['confirm' => __('Confirm transfer'), 'reject' => __('Reject')], 'paid' => ['reject' => __('Reject & refund')], default => [] })
                            @foreach ($options as $decision => $label)
                                <form method="POST" action="{{ route('admin.marketing.coupon-participation.decide', $participation->id) }}">@csrf<input type="hidden" name="decision" value="{{ $decision }}"><x-ui.button type="submit" size="sm" :variant="$decision === 'reject' ? 'outline' : 'primary'">{{ $label }}</x-ui.button></form>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endforeach
    {{ $invitations->links() }}
</x-layouts.panel>
