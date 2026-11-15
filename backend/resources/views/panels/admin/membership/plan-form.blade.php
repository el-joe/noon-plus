<x-layouts.panel :title="__('Edit :name', ['name' => $plan->name_en])" :subtitle="__('Price changes apply from each member\'s next renewal.')" :breadcrumbs="[__('Finance'), __('Nawy One'), __('Edit')]">
    <x-ui.card>
        <form method="POST" action="{{ route('admin.membership.plans.update', $plan->id) }}" class="grid gap-3 sm:grid-cols-4">
            @csrf @method('PUT')
            @include('panels.admin.membership._plan-fields', ['plan' => $plan])
            <div class="flex gap-2 sm:col-span-4"><x-ui.button type="submit">{{ __('Save plan') }}</x-ui.button><x-ui.button variant="ghost" :href="route('admin.membership')">{{ __('Cancel') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
