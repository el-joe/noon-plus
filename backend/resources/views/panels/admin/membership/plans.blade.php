<x-layouts.panel :title="__('Nawy One plans')" :subtitle="__('Membership plans in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Nawy One')]">
    @include('panels.admin.membership._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Plan'), __('Billing'), __('Price'), __('Trial'), __('Free delivery'), __('Members'), __('Status'), '']" :rows="$plans->isNotEmpty()" :empty="__('No plans yet — Nawy One cannot be bought in this country.')">
            @foreach ($plans as $plan)
                <tr class="border-t border-line" data-plan="{{ $plan->code }}">
                    <td class="px-5 py-3 font-medium">{{ $plan->name_en }}<span class="block text-xs text-fg-secondary" dir="rtl">{{ $plan->name_ar }}</span></td>
                    <td class="px-5 py-3">{{ ucfirst($plan->interval->value) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $plan->price" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">{{ trans_choice(':count day|:count days', $plan->trial_days) }}</td>
                    <td class="px-5 py-3">{{ $plan->free_shipping ? __('Yes') : __('No') }}</td>
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.membership.subscriptions', ['plan' => $plan->id]) }}">{{ $plan->members_count }}</a></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$plan->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@can('membership.manage')<x-ui.button variant="ghost" size="sm" :href="route('admin.membership.plans.edit', $plan->id)">{{ __('Edit') }}</x-ui.button>@endcan</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('membership.manage')
        <x-ui.card :title="__('Add a plan')">
            <form method="POST" action="{{ route('admin.membership.plans.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                @include('panels.admin.membership._plan-fields', ['plan' => null])
                <div><x-ui.button type="submit">{{ __('Add plan') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
