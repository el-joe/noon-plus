<x-ui.input :label="__('SKU')" name="sku" :value="$supply?->sku" required />
<x-ui.input :label="__('Name (English)')" name="name_en" :value="$supply?->name_en" required />
<x-ui.input :label="__('Name (Arabic)')" name="name_ar" :value="$supply?->name_ar" required />
<x-ui.input :label="__('Unit cost')" name="unit_cost" :value="$supply ? (string) $supply->unit_cost : null" inputmode="decimal" required />
<x-ui.input :label="__('Minimum quantity')" name="min_quantity" type="number" min="1" :value="$supply?->min_quantity ?? 1" required />
<x-ui.input :label="__('Maximum quantity')" name="max_quantity" type="number" min="1" :value="$supply?->max_quantity" />
<x-ui.input :label="__('Sort order')" name="sort_order" type="number" min="0" :value="$supply?->sort_order ?? 0" />
<x-ui.input :label="__('Description (English)')" name="description_en" :value="$supply?->description_en" />
<x-ui.input :label="__('Description (Arabic)')" name="description_ar" :value="$supply?->description_ar" />
<x-ui.checkbox :label="__('Active')" name="is_active" :checked="$supply?->is_active ?? true" />
