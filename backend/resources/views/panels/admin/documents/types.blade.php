<x-layouts.panel :title="__('Document types')" :subtitle="__('Requirement per business type and category in this country')" :breadcrumbs="[__('Operations'), __('Document review'), __('Types')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Code'), __('Name'), __('Business type'), __('Category'), __('Requirement')]" :rows="$types->isNotEmpty()">
            @foreach ($types as $type)
                <tr class="border-t border-line"><td class="px-5 py-3 font-mono text-xs">{{ $type->code }}</td><td class="px-5 py-3">{{ $type->name_en }}</td><td class="px-5 py-3 text-xs">{{ $type->business_type->value }}</td><td class="px-5 py-3 text-xs">{{ $type->category_id ? $categories->get($type->category_id)?->name_en : __('All') }}</td><td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', $type->requirement->value) }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    <x-ui.card :title="__('Add or update')">
        <form method="POST" action="{{ route('admin.documents.types.store') }}" class="grid gap-4 sm:grid-cols-4">
            @csrf
            <x-ui.input :label="__('Code')" name="code" required />
            <x-ui.input :label="__('Name (English)')" name="name_en" required />
            <x-ui.input :label="__('Name (Arabic)')" name="name_ar" required dir="rtl" />
            <x-ui.select :label="__('Business type')" name="business_type" :options="collect($owners)->mapWithKeys(fn ($o) => [$o->value => str_replace('_', ' ', $o->value)])->all()" />
            <x-ui.select :label="__('Category')" name="category_id" :options="$categories->pluck('name_en', 'id')->all()" :placeholder="__('All categories')" />
            <x-ui.select :label="__('Requirement')" name="requirement" :options="collect($requirements)->mapWithKeys(fn ($r) => [$r->value => str_replace('_', ' ', $r->value)])->all()" />
            <x-ui.checkbox :label="__('Has an expiry date')" name="has_expiry" />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
