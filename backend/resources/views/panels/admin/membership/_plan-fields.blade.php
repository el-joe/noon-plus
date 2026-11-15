<x-ui.input :label="__('Code')" name="code" :value="$plan?->code" required />
<x-ui.input :label="__('Name (English)')" name="name_en" :value="$plan?->name_en" required />
<x-ui.input :label="__('Name (Arabic)')" name="name_ar" :value="$plan?->name_ar" dir="rtl" required />
<x-ui.select :label="__('Billing')" name="interval" :selected="$plan?->interval?->value ?? 'monthly'" :options="['monthly' => __('Monthly'), 'annual' => __('Annual')]" />
<x-ui.input :label="__('Price (:currency, incl. VAT)', ['currency' => $country->currency])" name="price" :value="$plan?->price" inputmode="decimal" required />
<x-ui.input :label="__('Trial days')" name="trial_days" type="number" :value="$plan?->trial_days ?? 0" required />
<div class="flex flex-col justify-end gap-2">
    <x-ui.checkbox :label="__('Free delivery benefit')" name="free_shipping" :checked="$plan?->free_shipping ?? true" />
    <x-ui.checkbox :label="__('Open for new members')" name="is_active" :checked="$plan?->is_active ?? true" />
</div>
