<x-layouts.panel :title="$plan->name_en" :subtitle="__('Seller plan in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Vendor subscriptions'), __('Plans'), $plan->name_en]">
    <x-ui.card>
        <form method="POST" action="{{ route('admin.finance.subscription-plans.update', $plan->id) }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            @method('PUT')
            @include('panels.admin.finance.subscriptions._plan-fields', ['plan' => $plan])
            <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
