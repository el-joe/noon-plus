<x-ui.input :label="__('Code')" name="code" :value="$plan?->code" required />
<x-ui.input :label="__('Name (English)')" name="name_en" :value="$plan?->name_en" required />
<x-ui.input :label="__('Name (Arabic)')" name="name_ar" :value="$plan?->name_ar" dir="rtl" required />
<x-ui.select :label="__('Billing')" name="interval" :selected="$plan?->interval?->value ?? 'monthly'" :options="['monthly' => __('Monthly'), 'annual' => __('Annual')]" />
<x-ui.input :label="__('Price (:currency, incl. VAT)', ['currency' => $country->currency])" name="price" :value="$plan?->price" inputmode="decimal" required />
<x-ui.input :label="__('Trial days')" name="trial_days" type="number" :value="$plan?->trial_days ?? 0" required />
<x-ui.input :label="__('Active listings limit')" name="max_active_listings" type="number" :value="$plan?->max_active_listings" :helper="__('Leave empty for no limit.')" />
<x-ui.input :label="__('Sort order')" name="sort_order" type="number" :value="$plan?->sort_order ?? 0" />
<div class="sm:col-span-3">
    <x-ui.textarea :label="__('Features (one per line)')" name="features" :value="implode(PHP_EOL, $plan?->features ?? [])" />
</div>
<div class="flex flex-col justify-end gap-2">
    <x-ui.checkbox :label="__('Open for new subscribers')" name="is_active" :checked="$plan?->is_active ?? true" />
</div>
