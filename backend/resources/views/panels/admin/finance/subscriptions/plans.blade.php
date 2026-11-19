<x-layouts.panel :title="__('Seller plans')" :subtitle="__('Vendor subscription plans in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Vendor subscriptions'), __('Plans')]">
    @include('panels.admin.finance.subscriptions._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Plan'), __('Billing'), __('Price'), __('Trial'), __('Listings limit'), __('Subscribers'), __('Status'), '']" :rows="$plans->isNotEmpty()" :empty="__('No seller plans yet.')">
            @foreach ($plans as $plan)
                <tr class="border-t border-line" data-plan="{{ $plan->code }}">
                    <td class="px-5 py-3 font-medium">{{ $plan->name_en }}<span class="block text-xs text-fg-secondary" dir="rtl">{{ $plan->name_ar }}</span>@if ($plan->features)<span class="block text-xs text-fg-secondary">{{ implode(' · ', $plan->features) }}</span>@endif</td>
                    <td class="px-5 py-3">{{ \App\Shared\Localization\Label::of($plan->interval) }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $plan->price" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">{{ trans_choice(':count day|:count days', $plan->trial_days) }}</td>
                    <td class="px-5 py-3">{{ $plan->max_active_listings ?? __('No limit') }}</td>
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.finance.subscriptions', ['plan' => $plan->id]) }}">{{ $plan->subscribers_count }}</a></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$plan->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@can('vendor_subscriptions.manage')<x-ui.button variant="ghost" size="sm" :href="route('admin.finance.subscription-plans.edit', $plan->id)">{{ __('Edit') }}</x-ui.button>@endcan</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('vendor_subscriptions.manage')
        <x-ui.card :title="__('Add a plan')" :subtitle="__('Price changes apply from the next billed period; a plan in use is switched off instead of deleted.')">
            <form method="POST" action="{{ route('admin.finance.subscription-plans.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                @include('panels.admin.finance.subscriptions._plan-fields', ['plan' => null])
                <div><x-ui.button type="submit">{{ __('Add plan') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
