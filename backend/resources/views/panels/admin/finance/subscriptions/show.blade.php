<x-layouts.panel :title="$subscription->vendor?->store_name ?? 'V-'.$subscription->vendor_id" :subtitle="__('Seller plan in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('Vendor subscriptions'), __('Subscription')]">
    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Subscription')">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Plan') }}</dt><dd>{{ $subscription->plan?->name_en }}@if ($subscription->pendingPlan) <span class="block text-xs text-fg-secondary">{{ __('Next period: :plan', ['plan' => $subscription->pendingPlan->name_en]) }}</span>@endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$subscription->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Current period (Oman)') }}</dt><dd>{{ $subscription->current_period_start?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }} – {{ $subscription->current_period_end?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Pays by') }}</dt><dd>{{ \App\Shared\Localization\Label::of($subscription->payment_preference) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Ends at period end') }}</dt><dd>{{ $subscription->cancel_at_period_end ? __('Yes') : __('No') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Past due since') }}</dt><dd>{{ $subscription->past_due_since?->setTimezone(config('nawy.admin_timezone'))->format('j M Y') ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        @can('vendor_subscriptions.manage')
            @if ($subscription->status->isOpen())
                <x-ui.card :title="__('Actions')">
                    <div class="flex flex-col gap-3">
                        <form method="POST" action="{{ route('admin.finance.subscriptions.plan', $subscription->id) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-ui.select :label="__('Change plan')" name="plan_id" :selected="$subscription->pending_plan_id ?? $subscription->vendor_subscription_plan_id" :options="$activePlans" />
                            <x-ui.button type="submit" variant="outline">{{ __('Save') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.finance.subscriptions.cancel', $subscription->id) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-ui.input :label="__('Reason')" name="reason" required />
                            <x-ui.button type="submit" variant="danger">{{ __('Cancel now') }}</x-ui.button>
                        </form>
                    </div>
                </x-ui.card>
            @endif
        @endcan
    </section>

    <x-ui.card :title="__('Invoices')" flush>
        <x-ui.table :headers="[__('Invoice'), __('Period'), __('Amount'), __('Due'), __('Paid'), __('Status'), '']" :rows="$subscription->invoices->isNotEmpty()" :empty="__('No invoices.')">
            @foreach ($subscription->invoices as $invoice)
                <tr class="border-t border-line" data-invoice="{{ $invoice->number }}">
                    <td class="px-5 py-3">{{ $invoice->number }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $invoice->amount" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->due_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->paid_at ? $invoice->paid_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y').' · '.\App\Shared\Localization\Label::of($invoice->paid_via) : '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invoice->status" /></td>
                    <td class="px-5 py-3 text-end">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.finance.subscription-invoices.pdf', $invoice->id)">{{ __('PDF') }}</x-ui.button>
                            @if ($invoice->isPending())
                                @can('vendor_subscriptions.manage')
                                    <form method="POST" action="{{ route('admin.finance.subscription-invoices.deduct', $invoice->id) }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Deduct from payout') }}</x-ui.button></form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @include('panels.admin._partials.transitions', ['transitions' => $subscription->transitions])
</x-layouts.panel>
