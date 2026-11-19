<x-layouts.panel :title="__('Vendor subscriptions')" :subtitle="__('Seller plans in :country · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Vendor subscriptions')]">
    @include('panels.admin.finance.subscriptions._nav')

    <form method="GET" action="{{ route('admin.finance.subscriptions') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Seller')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Store name')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Finance\Enums\VendorSubscriptionStatus::cases())->mapWithKeys(fn ($status) => [$status->value => \App\Shared\Localization\Label::of($status)])->all()" />
        <x-ui.select :label="__('Plan')" name="plan" :selected="$filters['plan'] ?? ''" :placeholder="__('All')" :options="$plans" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Seller'), __('Plan'), __('Pays by'), __('Period ends (Oman)'), __('Status')]" :rows="$subscriptions->isNotEmpty()" :empty="__('No vendor subscriptions yet.')">
            @foreach ($subscriptions as $subscription)
                <tr class="border-t border-line" data-subscription="{{ $subscription->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.finance.subscriptions.show', $subscription->id) }}">{{ $subscription->vendor?->store_name ?? 'V-'.$subscription->vendor_id }}</a></td>
                    <td class="px-5 py-3">{{ $subscription->plan?->name_en }}</td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($subscription->payment_preference) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $subscription->current_period_end?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? '—' }}@if ($subscription->cancel_at_period_end) <span class="block text-fg-secondary">{{ __('Ends at period end') }}</span>@endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subscription->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $subscriptions->links() }}

    @can('vendor_subscriptions.manage')
        <x-ui.card :title="__('Subscribe a seller')">
            <form method="POST" action="{{ route('admin.finance.subscriptions.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Seller ID')" name="vendor_id" type="number" required />
                <x-ui.select :label="__('Plan')" name="plan_id" :options="$activePlans" />
                <x-ui.select :label="__('Pays by')" name="payment_preference" :options="['payout' => __('Payout'), 'card' => __('Card')]" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Subscribe') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
