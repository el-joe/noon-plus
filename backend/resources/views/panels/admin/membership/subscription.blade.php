<x-layouts.panel :title="$customer?->name ?? '#'.$membership->customer_user_id" :subtitle="__('Nawy One member in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Nawy One'), __('Subscription')]">
    <x-ui.card :title="__('Membership')">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-fg-secondary">{{ __('Email') }}</dt><dd>{{ $customer?->email }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Plan') }}</dt><dd>{{ $membership->plan?->name_en }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$membership->status" /></dd></div>
            <div><dt class="text-fg-secondary">{{ __('Current period (Oman)') }}</dt><dd>{{ $membership->current_period_start?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }} – {{ $membership->current_period_end?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Auto-renew') }}</dt><dd>{{ $membership->auto_renew ? __('On') : __('Off') }}</dd></div>
            <div><dt class="text-fg-secondary">{{ __('Past due since') }}</dt><dd>{{ $membership->past_due_since?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') ?? '—' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Invoices')" flush>
        <x-ui.table :headers="[__('Invoice'), __('Period'), __('Amount'), __('Paid (Oman)'), __('Status')]" :rows="$membership->invoices->isNotEmpty()" :empty="__('No invoices.')">
            @foreach ($membership->invoices as $invoice)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $invoice->number }}</td>
                    <td class="px-5 py-3 text-xs">{{ \Illuminate\Support\Carbon::parse($invoice->period_start)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($invoice->period_end)->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $invoice->amount" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->paid_at ? \Illuminate\Support\Carbon::parse($invoice->paid_at)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invoice->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @include('panels.admin._partials.transitions', ['transitions' => $membership->transitions])
</x-layouts.panel>
