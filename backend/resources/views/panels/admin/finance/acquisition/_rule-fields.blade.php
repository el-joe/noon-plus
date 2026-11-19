<x-ui.input :label="__('Name')" name="name" :value="$rule?->name" required />
<x-ui.select :label="__('Acquirer')" name="acquirer_user_id" :selected="$rule?->acquirer_user_id" :placeholder="__('Every acquirer')" :options="$acquirers->pluck('name', 'id')->all()" />
<x-ui.input :label="__('Monthly sales from (:currency)', ['currency' => $country->currency])" name="min_monthly_sales" :value="$rule?->min_monthly_sales ?? '0'" inputmode="decimal" required />
<x-ui.input :label="__('Monthly sales up to (:currency)', ['currency' => $country->currency])" name="max_monthly_sales" :value="$rule?->max_monthly_sales" inputmode="decimal" :helper="__('Leave empty for no upper limit.')" />
<x-ui.input :label="__('Rate (%)')" name="rate_percent" :value="$rule?->rate_percent" inputmode="decimal" required />
<x-ui.input :label="__('Valid from')" name="starts_on" type="date" :value="$rule?->starts_on?->toDateString() ?? now()->startOfMonth()->toDateString()" required />
<x-ui.input :label="__('Valid until')" name="ends_on" type="date" :value="$rule?->ends_on?->toDateString()" />
<div class="flex flex-col justify-end gap-2">
    <x-ui.checkbox :label="__('Active')" name="is_active" :checked="$rule?->is_active ?? true" />
</div>
