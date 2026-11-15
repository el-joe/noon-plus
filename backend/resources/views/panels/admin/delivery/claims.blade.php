<x-layouts.panel :title="__('Carrier claims')" :subtitle="__(':country · lost or damaged shipments: submitted → under review → approved / rejected → compensated', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Carrier claims')]">
    @include('panels.admin.delivery._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Claim'), __('Company'), __('Type'), __('Claimed'), __('Approved'), __('For'), __('Status'), '']" :rows="$claims->isNotEmpty()" :empty="__('No claims.')">
            @foreach ($claims as $claim)
                <tr class="border-t border-line align-top" data-claim="{{ $claim->id }}">
                    <td class="px-5 py-3 font-medium">{{ $claim->number }}<span class="block max-w-xs text-xs text-fg-secondary">{{ $claim->description }}</span></td>
                    <td class="px-5 py-3">{{ $claim->carrier->name }}</td>
                    <td class="px-5 py-3">{{ ucfirst($claim->type->value) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$claim->claimed_amount" :currency="$claim->currency" /></td>
                    <td class="px-5 py-3">@if ($claim->approved_amount !== null)<x-ui.money :amount="$claim->approved_amount" :currency="$claim->currency" />@else — @endif</td>
                    <td class="px-5 py-3">{{ $claim->compensate_to === 'customer' ? __('Customer credits') : __('Seller') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$claim->status" /></td>
                    <td class="px-5 py-3">
                        @can('carrier_claims.manage')
                            @switch($claim->status)
                                @case(\App\Domains\Delivery\Enums\ClaimStatus::Submitted)
                                    <form method="POST" action="{{ route('admin.delivery.claims.act', [$claim->id, 'review']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Start review') }}</x-ui.button></form>
                                    @break
                                @case(\App\Domains\Delivery\Enums\ClaimStatus::UnderReview)
                                    <form method="POST" action="{{ route('admin.delivery.claims.act', [$claim->id, 'approve']) }}" class="flex items-end gap-2">@csrf
                                        <x-ui.input :label="__('Amount')" name="amount" :id="'amount-'.$claim->id" :value="(string) app(\App\Shared\Money\MoneyFactory::class)->of((string) $claim->claimed_amount, $claim->currency)->getAmount()" />
                                        <x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button></form>
                                    <form method="POST" action="{{ route('admin.delivery.claims.act', [$claim->id, 'reject']) }}" class="mt-2 flex items-end gap-2">@csrf
                                        <x-ui.input :label="__('Reason')" name="note" :id="'note-'.$claim->id" />
                                        <x-ui.button type="submit" size="sm" variant="danger">{{ __('Reject') }}</x-ui.button></form>
                                    @break
                                @case(\App\Domains\Delivery\Enums\ClaimStatus::Approved)
                                    <form method="POST" action="{{ route('admin.delivery.claims.act', [$claim->id, 'compensate']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Mark compensated') }}</x-ui.button></form>
                                    @break
                            @endswitch
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $claims->links() }}

    @can('carrier_claims.manage')
        <x-ui.card :title="__('New claim')">
            <form method="POST" action="{{ route('admin.delivery.claims.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.input :label="__('Parcel number')" name="sub_order_number" required />
                <x-ui.select :label="__('Type')" name="type" :options="['lost' => __('Lost'), 'damaged' => __('Damaged')]" />
                <x-ui.input :label="__('Amount (:currency)', ['currency' => $country->currency])" name="amount" inputmode="decimal" required />
                <x-ui.select :label="__('Compensate')" name="compensate_to" :options="['vendor' => __('Seller'), 'customer' => __('Customer credits')]" />
                <div class="sm:col-span-2"><x-ui.textarea :label="__('What happened')" name="description" /></div>
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Submit claim') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
