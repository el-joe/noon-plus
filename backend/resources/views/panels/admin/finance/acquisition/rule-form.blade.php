<x-layouts.panel :title="$rule->name" :subtitle="__('Acquisition commission rule')" :breadcrumbs="[__('Finance'), __('Acquisition commissions'), __('Rules'), $rule->name]">
    <x-ui.card>
        <form method="POST" action="{{ route('admin.finance.acquisition-rules.update', $rule->id) }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            @method('PUT')
            @include('panels.admin.finance.acquisition._rule-fields', ['rule' => $rule])
            <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
