<x-ui.input :label="__('Code')" name="code" :id="$prefix.'-code'" :value="$warehouse?->code" required :helper="__('Capitals, digits and dashes, e.g. DXB-1')" />
<x-ui.input :label="__('Name (English)')" name="name_en" :id="$prefix.'-name-en'" :value="$warehouse?->name_en" required />
<x-ui.input :label="__('Name (Arabic)')" name="name_ar" :id="$prefix.'-name-ar'" :value="$warehouse?->name_ar" required dir="rtl" />
<x-ui.input :label="__('City')" name="city" :id="$prefix.'-city'" :value="$warehouse?->city" />
<div class="sm:col-span-2"><x-ui.input :label="__('Address')" name="address" :id="$prefix.'-address'" :value="$warehouse?->address" /></div>
<x-ui.select :label="__('Delivery zone')" name="shipping_zone_id" :id="$prefix.'-zone'" :selected="$warehouse?->shipping_zone_id" :placeholder="__('None')" :options="$zones" />
<x-ui.input :label="__('Capacity (cubic feet)')" name="capacity_cubic_feet" :id="$prefix.'-capacity'" :value="$warehouse?->capacity_cubic_feet" inputmode="decimal" />
<label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($warehouse?->is_active ?? true)> {{ __('Active') }}</label>
