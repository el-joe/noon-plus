@php($tz = $country->timezone)
<x-layouts.panel :title="__('Subscription')" :subtitle="__(':store · seller plan in :country', ['store' => $vendor->store_name, 'country' => $country->name_en])" :breadcrumbs="[__('Seller Center'), __('Finance'), __('Subscription')]">
    @if ($paymentResult)
        <div @if ($paymentResult['tone'] === 'danger') role="alert" @else role="status" @endif data-payment-result="{{ $paymentResult['tone'] }}"
             @class(['rounded-lg border px-4 py-3 text-sm', 'border-line bg-success-subtle text-fg-success' => $paymentResult['tone'] === 'success', 'border-line-danger bg-danger-subtle text-fg-danger' => $paymentResult['tone'] === 'danger', 'border-line bg-subtle text-fg' => $paymentResult['tone'] === 'info'])>{{ $paymentResult['message'] }}</div>
    @endif
    @if ($restricted)
        <p class="rounded-lg border border-line bg-subtle px-4 py-3 text-sm" data-subscription-restricted>{{ __('Your subscription is past due or expired. Listing submissions are paused until the open invoice is paid or you subscribe again.') }}</p>
    @endif

    @if ($subscription)
        <section class="grid gap-4 lg:grid-cols-2" data-subscription="{{ $subscription->status->value }}">
            <x-ui.card :title="__('Your plan')">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-fg-secondary">{{ __('Plan') }}</dt><dd class="font-medium">{{ $subscription->plan->name() }}</dd></div>
                    <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$subscription->status" /></dd></div>
                    <div><dt class="text-fg-secondary">{{ $subscription->status === \App\Domains\Finance\Enums\VendorSubscriptionStatus::Trialing ? __('Trial ends') : __('Current period') }}</dt><dd>{{ $subscription->current_period_start?->setTimezone($tz)->format('j M Y') }} – {{ $subscription->current_period_end?->setTimezone($tz)->format('j M Y') }}</dd></div>
                    <div><dt class="text-fg-secondary">{{ __('Price') }}</dt><dd><x-ui.money :amount="(string) $subscription->plan->price" :currency="$country->currency" /> / {{ \App\Shared\Localization\Label::of($subscription->plan->interval) }}</dd></div>
                    <div><dt class="text-fg-secondary">{{ __('Active listings limit') }}</dt><dd>{{ $limit ?? __('No limit') }}</dd></div>
                    @if ($subscription->pendingPlan)
                        <div><dt class="text-fg-secondary">{{ __('From the next period') }}</dt><dd>{{ $subscription->pendingPlan->name() }}</dd></div>
                    @endif
                </dl>
                @if ($subscription->plan->features)
                    <ul class="mt-4 list-disc ps-5 text-sm">
                        @foreach ($subscription->plan->features as $feature)<li>{{ $feature }}</li>@endforeach
                    </ul>
                @endif
                @if ($subscription->cancel_at_period_end)
                    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        <span>{{ __('Your plan ends on :date.', ['date' => $subscription->current_period_end?->setTimezone($tz)->format('j M Y')]) }}</span>
                        <form method="POST" action="{{ route('partner.subscription.resume') }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Keep my plan') }}</x-ui.button></form>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Manage')">
                <div class="flex flex-col gap-4">
                    <form method="POST" action="{{ route('partner.subscription.preference') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.select :label="__('Pay invoices by')" name="payment_preference" :selected="$subscription->payment_preference->value" :options="['payout' => __('Deduct from my payouts'), 'card' => __('Card')]" />
                        <x-ui.button type="submit" variant="outline">{{ __('Save') }}</x-ui.button>
                    </form>
                    {{-- Auto-renew: card invoices are charged to a saved card (gateway token only); declines are retried, then pay by hand. --}}
                    <form method="POST" action="{{ route('partner.subscription.auto-renew') }}" class="flex flex-col gap-2 rounded-lg border border-line p-3" data-auto-renew>
                        @csrf
                        <p class="text-sm font-medium">{{ __('Auto-renew with a saved card') }}
                            @if ($subscription->auto_renew)<x-ui.badge tone="success">{{ __('On') }}</x-ui.badge>@else<x-ui.badge>{{ __('Off') }}</x-ui.badge>@endif
                        </p>
                        @if ($savedCards->isEmpty())
                            <p class="text-xs text-fg-secondary">{{ __('Pay an invoice by card once to save the card (only the gateway keeps the card; Nawy stores a token).') }}</p>
                        @else
                            <x-ui.select :label="__('Card')" name="saved_payment_card_id" :selected="(string) ($subscription->saved_payment_card_id ?? '')"
                                :options="$savedCards->mapWithKeys(fn ($card) => [$card->id => ucfirst((string) $card->brand).' •••• '.$card->last4.($card->exp_month ? ' · '.str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT).'/'.$card->exp_year : '')])->all()" />
                            <div class="flex gap-2">
                                <x-ui.button type="submit" size="sm" name="enabled" value="1">{{ $subscription->auto_renew ? __('Save card') : __('Turn on') }}</x-ui.button>
                                @if ($subscription->auto_renew)<x-ui.button type="submit" size="sm" variant="outline" name="enabled" value="0">{{ __('Turn off') }}</x-ui.button>@endif
                            </div>
                        @endif
                    </form>
                    <form method="POST" action="{{ route('partner.subscription.plan') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-ui.select :label="__('Change plan')" name="plan_id" :selected="$subscription->pending_plan_id ?? $subscription->vendor_subscription_plan_id" :options="$plans->mapWithKeys(fn ($plan) => [$plan->id => $plan->name().' · '.app(\App\Shared\Money\MoneyFormatter::class)->format((string) $plan->price, $country->currency)])->all()" />
                        <x-ui.button type="submit" variant="outline">{{ __('Change') }}</x-ui.button>
                    </form>
                    <p class="text-xs text-fg-secondary">{{ __('A new plan starts with the next billing period (at once during a trial).') }}</p>
                    @unless ($subscription->cancel_at_period_end)
                        <form method="POST" action="{{ route('partner.subscription.cancel') }}" onsubmit="return confirm(@js(__('Cancel your plan?')))">
                            @csrf
                            <x-ui.button type="submit" variant="danger">{{ $subscription->status === \App\Domains\Finance\Enums\VendorSubscriptionStatus::Active ? __('Cancel at period end') : __('Cancel plan') }}</x-ui.button>
                        </form>
                    @endunless
                </div>
            </x-ui.card>
        </section>
    @else
        <x-ui.card :title="__('Choose a plan')" :subtitle="$latest ? __('Your last plan :plan is :status.', ['plan' => $latest->plan?->name(), 'status' => \App\Shared\Localization\Label::of($latest->status)]) : __('Invoices are deducted from your payouts or paid by card. Unpaid invoices have a :days-day grace.', ['days' => $graceDays])">
            @if ($plans->isEmpty())
                <p class="text-sm text-fg-secondary">{{ __('No seller plans are offered in this country yet.') }}</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($plans as $plan)
                        <form method="POST" action="{{ route('partner.subscription.subscribe') }}" class="flex flex-col gap-3 rounded-xl border border-line p-4" data-plan="{{ $plan->code }}">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <div class="font-semibold">{{ $plan->name() }}</div>
                            <div><x-ui.money :amount="(string) $plan->price" :currency="$country->currency" /> / {{ \App\Shared\Localization\Label::of($plan->interval) }}</div>
                            @if ($plan->trial_days > 0 && $latest === null)
                                <div class="text-xs text-fg-secondary">{{ trans_choice(':count day free trial|:count days free trial', $plan->trial_days) }}</div>
                            @endif
                            <ul class="list-disc ps-5 text-sm">
                                <li>{{ $plan->max_active_listings ? trans_choice('Up to :count active listing|Up to :count active listings', $plan->max_active_listings) : __('Unlimited active listings') }}</li>
                                @foreach ($plan->features ?? [] as $feature)<li>{{ $feature }}</li>@endforeach
                            </ul>
                            <x-ui.select :label="__('Pay invoices by')" name="payment_preference" :options="['payout' => __('Deduct from my payouts'), 'card' => __('Card')]" />
                            <x-ui.button type="submit">{{ __('Subscribe') }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Invoices')" flush>
        <x-ui.table :headers="[__('Invoice'), __('Plan'), __('Period'), __('Amount'), __('Due'), __('Status'), '']" :rows="$invoices->isNotEmpty()" :empty="__('No invoices yet.')">
            @foreach ($invoices as $invoice)
                <tr class="border-t border-line" data-invoice="{{ $invoice->number }}">
                    <td class="px-5 py-3 font-medium">{{ $invoice->number }}</td>
                    <td class="px-5 py-3">{{ $invoice->plan?->name() }}</td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->period_start->setTimezone($tz)->format('j M Y') }} – {{ $invoice->period_end->setTimezone($tz)->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $invoice->amount" :currency="$invoice->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $invoice->due_at->setTimezone($tz)->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$invoice->status" />@if ($invoice->paid_via)<span class="block text-xs text-fg-secondary">{{ \App\Shared\Localization\Label::of($invoice->paid_via) }}</span>@endif
                        @if ($invoice->charge_currency)<span class="block text-xs text-fg-secondary" data-charge>{{ __('Charged :amount :currency at :rate', ['amount' => rtrim(rtrim((string) $invoice->charge_amount, '0'), '.'), 'currency' => $invoice->charge_currency, 'rate' => rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.')]) }}</span>@endif
                        @if ($invoice->isPending() && $invoice->auto_charge_failure)<span class="block text-xs text-fg-danger" data-auto-charge-failure>{{ $invoice->auto_charge_failure }}@if ($invoice->next_auto_charge_at) · {{ __('retry :date', ['date' => $invoice->next_auto_charge_at->setTimezone($tz)->format('j M H:i')]) }}@endif</span>@endif</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-ui.button variant="ghost" size="sm" :href="route('partner.subscription.invoices.pdf', $invoice->id)">{{ __('PDF') }}</x-ui.button>
                            @if ($invoice->isPending())
                                @foreach ($cardMethods as $method)
                                    <form method="POST" action="{{ route('partner.subscription.invoices.pay', $invoice->id) }}">@csrf<input type="hidden" name="method" value="{{ $method }}"><x-ui.button type="submit" size="sm">{{ __('Pay with :method', ['method' => \App\Shared\Localization\Label::of($method)]) }}</x-ui.button></form>
                                @endforeach
                                <form method="POST" action="{{ route('partner.subscription.invoices.pay', $invoice->id) }}">@csrf<input type="hidden" name="method" value="payout"><x-ui.button type="submit" variant="outline" size="sm">{{ __('Deduct from payout') }}</x-ui.button></form>
                                <form method="POST" action="{{ route('partner.subscription.invoices.verify', $invoice->id) }}">@csrf<x-ui.button type="submit" variant="ghost" size="sm">{{ __('Check payment') }}</x-ui.button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $invoices->links() }}

    @if ($subscription)
        @include('panels.admin._partials.transitions', ['transitions' => $subscription->transitions, 'timezone' => $tz])
    @endif
</x-layouts.panel>
