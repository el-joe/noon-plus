<x-layouts.panel :title="$category->name_en" :subtitle="__('Category in :country · :slug', ['country' => $country->name_en, 'slug' => $category->slug])" :breadcrumbs="[__('Catalog'), __('Categories'), $category->name_en]">
    <x-slot:actions>
        @if ($canManage)
            <x-ui.button variant="outline" :href="route('admin.catalog.categories.create', ['parent' => $category->id])">{{ __('Add sub-category') }}</x-ui.button>
            <x-ui.button :href="route('admin.catalog.categories.edit', $category->id)">{{ __('Edit') }}</x-ui.button>
        @endif
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-3">
        <x-ui.card :title="__('Details')" class="lg:col-span-2">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Name (English)') }}</dt><dd class="font-medium">{{ $category->name_en }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Name (Arabic)') }}</dt><dd class="font-medium" dir="rtl">{{ $category->name_ar }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Parent') }}</dt><dd>@if ($category->parent)<a class="text-fg-link" href="{{ route('admin.catalog.categories.show', $category->parent->id) }}">{{ $category->parent->name_en }}</a>@else {{ __('Top level') }} @endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$category->is_active ? 'active' : 'inactive'" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Commission rate') }}</dt><dd>{{ $category->commission_rate !== null ? $category->commission_rate.'%' : __('Platform default') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Return window') }}</dt><dd>{{ $category->return_window_days !== null ? trans_choice(':count day|:count days', $category->return_window_days) : __('Platform default') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Seller contract required') }}</dt><dd>{{ $category->requires_contract ? __('Yes') : __('No') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Certification required') }}</dt><dd>{{ $category->requires_certification ? __('Yes') : __('No') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Size guide') }}</dt><dd>{{ $category->sizeGuide?->name ?? '—' }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Products') }}</dt><dd><a class="text-fg-link" href="{{ route('admin.catalog.products', ['category' => $category->id]) }}">{{ $category->products_count }}</a></dd></div>
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('Image')">
            @if ($category->image_path)
                <img src="{{ \App\Shared\Http\PublicUrl::of($category->image_path) }}" alt="{{ $category->name_en }}" class="aspect-square w-full rounded-lg object-cover">
            @else
                <p class="text-sm text-fg-secondary">{{ __('No image yet.') }}</p>
            @endif
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Attributes & filters')" :subtitle="__('Sellers fill these in; filterable ones appear as storefront filters.')" flush>
        <x-ui.table :headers="[__('Key'), __('Label'), __('Type'), __('Options'), __('Required'), __('Filter'), '']" :rows="$category->attributeDefinitions->isNotEmpty()" :empty="__('No attributes yet.')">
            @foreach ($category->attributeDefinitions as $attribute)
                <tr class="border-t border-line" data-attribute="{{ $attribute->key }}">
                    <td class="px-5 py-3 font-mono text-xs">{{ $attribute->key }}</td>
                    <td class="px-5 py-3">{{ $attribute->label_en }} <span class="text-fg-secondary" dir="rtl">· {{ $attribute->label_ar }}</span></td>
                    <td class="px-5 py-3">{{ ucfirst($attribute->type) }}</td>
                    <td class="px-5 py-3 text-xs">{{ implode(', ', $attribute->options ?? []) ?: '—' }}</td>
                    <td class="px-5 py-3">{{ $attribute->is_required ? __('Yes') : __('No') }}</td>
                    <td class="px-5 py-3">{{ $attribute->is_filterable ? __('Yes') : __('No') }}</td>
                    <td class="px-5 py-3 text-end">
                        @if ($canManage)
                            <form method="POST" action="{{ route('admin.catalog.attributes.destroy', $attribute->id) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="ghost" size="sm">{{ __('Remove') }}</x-ui.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($canManage)
            <form method="POST" action="{{ route('admin.catalog.categories.attributes.store', $category->id) }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Key')" name="key" placeholder="colour" />
                <x-ui.input :label="__('Label (English)')" name="label_en" />
                <x-ui.input :label="__('Label (Arabic)')" name="label_ar" dir="rtl" />
                <x-ui.select :label="__('Type')" name="type" :options="['text' => __('Text'), 'number' => __('Number'), 'select' => __('Select'), 'boolean' => __('Yes / no')]" />
                <x-ui.input :label="__('Options (comma separated, for select)')" name="options" class="sm:col-span-2" />
                <div class="flex flex-col justify-end gap-2">
                    <x-ui.checkbox :label="__('Required')" name="is_required" />
                    <x-ui.checkbox :label="__('Shown as a filter')" name="is_filterable" />
                </div>
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Add attribute') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Sub-categories')" flush>
        <x-ui.table :headers="[__('Category'), __('Slug'), __('Commission'), __('Products'), __('Status')]" :rows="$category->children->isNotEmpty()" :empty="__('No sub-categories.')">
            @foreach ($category->children as $child)
                @include('panels.admin.catalog._category-row', ['category' => $child, 'depth' => 0, 'nested' => false])
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
