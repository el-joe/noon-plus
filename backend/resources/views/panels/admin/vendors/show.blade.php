@php($fmt = app(\App\Shared\Money\MoneyFormatter::class))
<x-layouts.panel :title="$vendor->store_name" :subtitle="__('Seller V-:id in :country', ['id' => $vendor->id, 'country' => $country->name_en])" :breadcrumbs="[__('Users & vendors'), __('Vendors'), $vendor->store_name]">
    @if ($vendor->isTravelAgency())
        {{-- Owner decision D11: travel pays no commission and no campaign fees (paid ad packages still apply). --}}
        <p class="rounded-lg border border-line bg-subtle px-4 py-3 text-sm" data-travel-no-commission>{{ __('Travel agency: no commission and no marketer-campaign fees on Nawy. Paid ad packages still apply.') }}</p>
    @else
        <section class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card :label="__('Gross sales settled')" :value="$economics ? $fmt->format($economics['gross'], $country->currency) : '•••'" />
            <x-ui.stat-card :label="__('Commission')" :value="$economics ? $fmt->format($economics['commission'], $country->currency) : '•••'" data-masked="{{ $economics ? 'false' : 'true' }}" />
            <x-ui.stat-card :label="__('Net to seller')" :value="$economics ? $fmt->format($economics['net'], $country->currency) : '•••'" />
        </section>
    @endif

    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Profile')">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Owner') }}</dt><dd>{{ $owner?->name }} · {{ $owner?->email }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Type') }}</dt><dd>{{ ucfirst($vendor->type) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$vendor->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Rating') }}</dt><dd>{{ $vendor->rating ?? '—' }} ({{ $vendor->rating_count ?? 0 }})</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Listings') }}</dt><dd>{{ $listingCounts->map(fn ($total, $status) => \App\Shared\Localization\Label::of($status).': '.$total)->implode(', ') ?: '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Account manager') }}</dt><dd>{{ $manager?->name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Acquired by') }}</dt><dd data-acquirer>{{ $acquirer?->name ?? '—' }}{{ $vendor->acquired_at ? ' · '.$vendor->acquired_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y') : '' }}</dd></div>
            </dl>
        </x-ui.card>

        <div class="flex flex-col gap-4">
            @can('vendors.assign_account_manager')
                <x-ui.card :title="__('Account manager')">
                    <form method="POST" action="{{ route('admin.vendors.account-manager', $vendor->id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.select :label="__('Assigned to')" name="account_manager_id" :selected="$vendor->account_manager_id" :placeholder="__('Nobody')" :options="$managers->pluck('name', 'id')->all()" />
                        <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                    </form>
                </x-ui.card>
                <x-ui.card :title="__('Acquisition')" :subtitle="__('Earns the monthly acquisition commission on this seller\'s sales.')">
                    <form method="POST" action="{{ route('admin.vendors.acquirer', $vendor->id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.select :label="__('Acquired by')" name="acquired_by_user_id" :selected="$vendor->acquired_by_user_id" :placeholder="__('Nobody')" :options="$acquirers->pluck('name', 'id')->all()" />
                        <x-ui.input :label="__('Acquired on')" name="acquired_at" type="date" :value="$vendor->acquired_at?->toDateString()" />
                        <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
            @can('vendors.update')
                <x-ui.card :title="__('Status')">
                    <form method="POST" action="{{ route('admin.vendors.status', $vendor->id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.select :label="__('Status')" name="status" :selected="$vendor->status" :options="['active' => __('Active'), 'on_hold' => __('On hold'), 'suspended' => __('Suspended'), 'blacklisted' => __('Blacklisted')]" />
                        <x-ui.input :label="__('Reason')" name="reason" required />
                        <x-ui.button type="submit" variant="outline">{{ __('Update') }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
        </div>
    </section>

    <x-ui.card :title="__('Latest shipments')" flush>
        <x-ui.table :headers="[__('Shipment'), __('Total'), __('Placed (Oman)'), __('Status')]" :rows="$recentShipments->isNotEmpty()" :empty="__('No shipments yet.')">
            @foreach ($recentShipments as $subOrder)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">@can('orders.view')<a class="text-fg-link" href="{{ route('admin.orders.show', $subOrder->order_id) }}">{{ $subOrder->number }}</a>@else {{ $subOrder->number }} @endcan</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $subOrder->total" :currency="$country->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $subOrder->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$subOrder->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
