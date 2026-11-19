<x-layouts.panel :title="__('Acquisition commission rules')" :subtitle="__('Rate inside a monthly sales band, valid for a date range · :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Acquisition commissions'), __('Rules')]">
    @include('panels.admin.finance.acquisition._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Rule'), __('Acquirer'), __('Monthly sales band'), __('Rate'), __('Valid'), __('Status'), '']" :rows="$rules->isNotEmpty()" :empty="__('No rules yet — no acquisition commission is paid in this country.')">
            @foreach ($rules as $rule)
                <tr class="border-t border-line" data-rule="{{ $rule->id }}">
                    <td class="px-5 py-3 font-medium">{{ $rule->name }}</td>
                    <td class="px-5 py-3">{{ $rule->acquirer_user_id ? ($acquirers->firstWhere('id', $rule->acquirer_user_id)?->name ?? '#'.$rule->acquirer_user_id) : __('Every acquirer') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $rule->min_monthly_sales" :currency="$country->currency" /> – @if ($rule->max_monthly_sales !== null)<x-ui.money :amount="(string) $rule->max_monthly_sales" :currency="$country->currency" />@else ∞ @endif</td>
                    <td class="px-5 py-3">{{ rtrim(rtrim((string) $rule->rate_percent, '0'), '.') }}%</td>
                    <td class="px-5 py-3 text-xs">{{ $rule->starts_on->format('j M Y') }} – {{ $rule->ends_on?->format('j M Y') ?? __('open') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$rule->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@can('acquisition_commissions.manage')<x-ui.button variant="ghost" size="sm" :href="route('admin.finance.acquisition-rules.edit', $rule->id)">{{ __('Edit') }}</x-ui.button>@endcan</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @can('acquisition_commissions.manage')
        <x-ui.card :title="__('Add a rule')" :subtitle="__('An acquirer\'s own rule wins over a rule for every acquirer; among matching bands the highest one applies.')">
            <form method="POST" action="{{ route('admin.finance.acquisition-rules.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                @include('panels.admin.finance.acquisition._rule-fields', ['rule' => null])
                <div><x-ui.button type="submit">{{ __('Add rule') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
