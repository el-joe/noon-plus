<x-layouts.panel :title="__('Nawy One subscriptions')" :subtitle="__('Members in :country · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Nawy One'), __('Subscriptions')]">
    @include('panels.admin.membership._nav')

    <form method="GET" action="{{ route('admin.membership.subscriptions') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Customer')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Name or email')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="collect(\App\Domains\Membership\Enums\MembershipStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
        <x-ui.select :label="__('Plan')" name="plan" :selected="$filters['plan'] ?? ''" :placeholder="__('All')" :options="$plans" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Customer'), __('Plan'), __('Period ends (Oman)'), __('Auto-renew'), __('Status')]" :rows="$memberships->isNotEmpty()" :empty="__('No members yet.')">
            @foreach ($memberships as $membership)
                <tr class="border-t border-line" data-membership="{{ $membership->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.membership.subscriptions.show', $membership->id) }}">{{ $customers->get($membership->customer_user_id)?->name ?? '#'.$membership->customer_user_id }}</a><span class="block text-xs text-fg-secondary">{{ $customers->get($membership->customer_user_id)?->email }}</span></td>
                    <td class="px-5 py-3">{{ $membership->plan?->name_en }}</td>
                    <td class="px-5 py-3 text-xs">{{ $membership->current_period_end?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $membership->auto_renew ? __('On') : __('Off') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$membership->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $memberships->links() }}
</x-layouts.panel>
