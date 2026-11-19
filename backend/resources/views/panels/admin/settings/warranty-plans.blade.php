<x-layouts.panel :title="__('Warranty plans')" :subtitle="__(':country · extended warranty sold at checkout per parent category · prices in :currency, VAT included', ['country' => $country->name_en, 'currency' => $country->currency])"
                 :breadcrumbs="[__('Catalog'), __('Warranty plans')]">
    @php($canUpdate = auth()->user()->can('warranty_plans.update'))
    @php($categoryOptions = $categories->mapWithKeys(fn ($category) => [$category->id => $category->name_en])->all())

    <x-ui.card :title="__('Plans')">
        <div class="flex flex-col gap-3">
            @forelse ($plans as $plan)
                <form method="POST" action="{{ route('admin.catalog.warranty-plans.update', $plan->id) }}" class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-4 xl:grid-cols-8" data-plan="{{ $plan->id }}">
                    @csrf
                    <input type="hidden" name="_scope" value="plan-{{ $plan->id }}">
                    @method('PUT')
                    <x-ui.select :scope="'plan-'.$plan->id" :label="__('Category')" name="category_id" :id="'category_id-'.$plan->id" :selected="$plan->category_id" :options="$categoryOptions + [$plan->category_id => $plan->category->name_en]" />
                    <x-ui.input :scope="'plan-'.$plan->id" :label="__('Name (EN)')" name="name_en" :id="'name_en-'.$plan->id" :value="$plan->name_en" />
                    <x-ui.input :scope="'plan-'.$plan->id" :label="__('Name (AR)')" name="name_ar" :id="'name_ar-'.$plan->id" :value="$plan->name_ar" dir="rtl" />
                    <x-ui.input :scope="'plan-'.$plan->id" :label="__('Months')" name="duration_months" :id="'duration_months-'.$plan->id" type="number" :value="$plan->duration_months" />
                    <x-ui.input :scope="'plan-'.$plan->id" :label="__('Price')" name="price" :id="'price-'.$plan->id" :value="$plan->price" />
                    <x-ui.input :scope="'plan-'.$plan->id" :label="__('Order')" name="sort_order" :id="'sort_order-'.$plan->id" type="number" :value="$plan->sort_order" />
                    <div class="flex items-end"><x-ui.checkbox :scope="'plan-'.$plan->id" :label="__('Active')" name="is_active" :id="'is_active-'.$plan->id" :checked="$plan->is_active" /></div>
                    <div class="flex items-end">@if ($canUpdate)<x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>@endif</div>
                </form>
            @empty
                <p class="text-sm text-fg-secondary">{{ __('No warranty plans yet.') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    @if ($canUpdate)
        <x-ui.card :title="__('New plan')">
            <form method="POST" action="{{ route('admin.catalog.warranty-plans.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="_scope" value="new-plan">
                <x-ui.select scope="new-plan" :label="__('Parent category')" name="category_id" :options="$categoryOptions" :placeholder="__('Choose')" />
                <x-ui.input scope="new-plan" :label="__('Name (EN)')" name="name_en" required />
                <x-ui.input scope="new-plan" :label="__('Name (AR)')" name="name_ar" dir="rtl" required />
                <x-ui.input scope="new-plan" :label="__('Months')" name="duration_months" type="number" min="1" required />
                <x-ui.input scope="new-plan" :label="__('Price')" name="price" required />
                <x-ui.input scope="new-plan" :label="__('Order')" name="sort_order" type="number" value="0" />
                <div class="flex items-end"><x-ui.checkbox scope="new-plan" :label="__('Active')" name="is_active" :checked="true" /></div>
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Add plan') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif
</x-layouts.panel>
