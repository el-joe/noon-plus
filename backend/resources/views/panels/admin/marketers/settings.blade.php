<x-layouts.panel :title="__('Marketer settings')" :subtitle="__(':country · commission defaults, marketing fees and campaign rules', ['country' => $country->name_en])" :breadcrumbs="[__('Growth'), __('Marketers'), __('Settings')]">
    @if ($uncovered !== [])
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('No collaboration contract covers: :types', ['types' => implode(', ', $uncovered)]) }}</div>
    @endif

    <x-ui.card :title="__('Commission defaults per category')" :subtitle="__('Used when a marketer has no profile rule. Blank = inherit from the parent row.')" flush>
        <x-ui.table :headers="[__('Category'), __('Influencer fixed'), __('Affiliate %'), __('Broker fixed'), __('Broker %'), __('Fee %'), __('Fee fixed'), '']">
            @foreach (collect([null])->concat($categories) as $category)
                @php($rule = $rules->get($category?->id ?? 0))
                @php($formId = 'rule-'.($category?->id ?? 'default'))
                <tr class="border-t border-line" data-rule-row="{{ $category?->id ?? 'default' }}">
                    <td class="px-5 py-2 text-sm font-medium">{{ $category?->name_en ?? __('Platform default') }}</td>
                    @foreach (['influencer_fixed', 'affiliate_percent', 'broker_fixed', 'broker_percent', 'fee_percent', 'fee_fixed'] as $field)
                        <td class="px-2 py-2"><input form="{{ $formId }}" name="{{ $field }}" value="{{ $rule?->{$field} === null ? '' : rtrim(rtrim((string) $rule->{$field}, '0'), '.') }}" type="number" step="0.0001" min="0" class="h-8 w-24 rounded border border-line px-2 text-xs" aria-label="{{ $field }}"></td>
                    @endforeach
                    <td class="px-2 py-2">
                        <form id="{{ $formId }}" method="POST" action="{{ route('admin.marketers.settings.rules') }}">
                            @csrf
                            <input type="hidden" name="category_id" value="{{ $category?->id }}">
                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Marketing fee — fixed')">
            <form method="POST" action="{{ route('admin.marketers.settings.fees') }}" class="grid gap-3 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="kind" value="fixed">
                <x-ui.input :label="__('Amount')" name="amount" type="number" step="0.001" min="0" :value="$fixedFee?->amount ?? 0" />
                <x-ui.input :label="__('% of base')" name="percent" type="number" step="0.01" min="0" :value="$fixedFee?->percent ?? 0" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Marketing fee — variable')" :subtitle="__('Per category × fulfilment')">
            <form method="POST" action="{{ route('admin.marketers.settings.fees') }}" class="grid gap-3 sm:grid-cols-5">
                @csrf
                <input type="hidden" name="kind" value="variable">
                <x-ui.select :label="__('Category')" name="category_id" :options="$categories->pluck('name_en', 'id')->all()" :placeholder="__('Any')" />
                <x-ui.select :label="__('Fulfilment')" name="fulfilment" :options="['fbm' => 'FBM', 'fbn' => 'FBN', 'cross_dock' => 'FBP / cross-dock']" :placeholder="__('Any')" />
                <x-ui.input :label="__('Amount')" name="amount" type="number" step="0.001" min="0" value="0" />
                <x-ui.input :label="__('%')" name="percent" type="number" step="0.01" min="0" value="0" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
            </form>
            <ul class="mt-3 flex flex-col gap-1 text-xs">
                @foreach ($variableFees as $fee)<li>{{ $categories->firstWhere('id', $fee->category_id)?->name_en ?? __('Any category') }} · {{ $fee->fulfilment ?? __('any') }} — {{ $fee->amount }} + {{ $fee->percent }}%</li>@endforeach
            </ul>
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Campaign rules')">
        <form method="POST" action="{{ route('admin.marketers.settings.general') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf
            <x-ui.input :label="__('Invitation timeout (hours)')" name="invitation_timeout_hours" type="number" :value="$general['invitation_timeout_hours']" />
            <x-ui.input :label="__('Auto-approve after (hours)')" name="auto_approve_hours" type="number" :value="$general['auto_approve_hours']" />
            <x-ui.input :label="__('Attribution window (days)')" name="attribution_window_days" type="number" :value="$general['attribution_window_days']" />
            <x-ui.input :label="__('Referral grace (days)')" name="referral_grace_days" type="number" :value="$general['referral_grace_days']" />
            <x-ui.input :label="__('Clearing days')" name="clearing_days" type="number" :value="$general['clearing_days']" />
            <x-ui.input :label="__('Minimum stock for campaigns')" name="min_stock_for_campaign" type="number" :value="$general['min_stock_for_campaign']" />
            <x-ui.select :label="__('Attribution model')" name="attribution_model" :options="['last_click' => __('Last click'), 'first_click' => __('First click'), 'linear' => __('Linear')]" :selected="$general['attribution_model']" />
            <x-ui.select :label="__('Campaign fee refund policy')" name="campaign_fee_refund_policy" :options="['none' => __('No refund after go-live'), 'before_first_conversion' => __('Refund before the first conversion'), 'pro_rata' => __('Pro rata of the unused period')]" :selected="$general['campaign_fee_refund_policy']" />
            <x-ui.checkbox :label="__('Cross-promotion obligations enabled')" name="cross_promotion_enabled" :checked="(bool) $general['cross_promotion_enabled']" />
            <div><x-ui.button type="submit">{{ __('Save rules') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
