<x-layouts.panel :title="__('Customers')" :subtitle="__('Customer accounts in :country', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Commerce'), __('Customers')]">

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Name or email')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :use-old="false"
                     :options="['active' => __('Active'), 'suspended' => __('Suspended'), 'banned' => __('Banned')]" />
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Customer'), __('Status'), __('Last sign-in'), __('Account')]" :rows="$customers->isNotEmpty()" :empty="__('No customers found.')">
            @foreach ($customers as $customer)
                <tr class="border-t border-line align-top" data-customer="{{ $customer->id }}">
                    <td class="px-5 py-3 text-sm">{{ $customer->name }}<span class="block text-xs text-fg-secondary">{{ $customer->email }}</span></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$customer->status" /></td>
                    <td class="px-5 py-3 text-xs">{{ $customer->last_login_at?->timezone(config('nawy.admin_timezone'))->format('j M Y H:i') ?? '—' }}</td>
                    <td class="px-5 py-3">
                        @can('customers.update')
                            @php($next = (\App\Domains\Customers\Enums\CustomerAccountStatus::tryFrom((string) $customer->status) ?? \App\Domains\Customers\Enums\CustomerAccountStatus::Active)->allowedNext())
                            @if ($next !== [])
                                <form method="POST" action="{{ route('admin.customers.status', $customer->id) }}" class="flex flex-wrap gap-2">
                                    @csrf
                                    <label class="sr-only" for="status-{{ $customer->id }}">{{ __('New status') }}</label>
                                    <select id="status-{{ $customer->id }}" name="status" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        @foreach ($next as $option)
                                            <option value="{{ $option->value }}">{{ \App\Shared\Localization\Label::of($option) }}</option>
                                        @endforeach
                                    </select>
                                    <label class="sr-only" for="reason-{{ $customer->id }}">{{ __('Reason') }}</label>
                                    <input id="reason-{{ $customer->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Apply') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $customers->links() }}
</x-layouts.panel>
