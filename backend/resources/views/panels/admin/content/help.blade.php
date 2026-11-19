<x-layouts.panel :title="$hub === 'knowledge_hub' ? __('Ads Knowledge Hub') : __('Help centre')" :subtitle="__(':country · collections and bilingual articles; pinned first, search EN + AR', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('Help')]">
    @include('panels.admin.content._nav')
    <div class="flex gap-2">
        <x-ui.button :href="route('admin.cms.help', ['hub' => 'help_center'])" size="sm" :variant="$hub === 'help_center' ? 'primary' : 'outline'">{{ __('Help centre') }}</x-ui.button>
        <x-ui.button :href="route('admin.cms.help', ['hub' => 'knowledge_hub'])" size="sm" :variant="$hub === 'knowledge_hub' ? 'primary' : 'outline'">{{ __('Knowledge hub') }}</x-ui.button>
    </div>

    @foreach ($collections as $collection)
        <x-ui.card :title="$collection->title_en.' · '.$collection->title_ar" :subtitle="$collection->audience.' · /'.$collection->slug" flush>
            <x-ui.table :headers="[__('Article'), __('Views'), __('Pinned'), __('Published')]" :rows="$collection->articles->isNotEmpty()" :empty="__('No articles yet.')">
                @foreach ($collection->articles as $article)
                    <tr class="border-t border-line" data-article="{{ $article->id }}"><td class="px-5 py-3">{{ $article->title_en }}<span class="block text-xs text-fg-secondary">/{{ $article->slug }}</span></td><td class="px-5 py-3">{{ $article->views }}</td><td class="px-5 py-3">{{ $article->is_pinned ? __('Yes') : '—' }}</td><td class="px-5 py-3">{{ $article->is_published ? __('Yes') : __('Draft') }}</td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endforeach

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('New article')" class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.cms.help.articles.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.select :label="__('Collection')" name="collection_id" :options="$collections->pluck('title_en', 'id')->all()" />
                <x-ui.input :label="__('Order')" name="sort_order" type="number" value="0" />
                <x-ui.input :label="__('Title (English)')" name="title_en" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" dir="rtl" required />
                <x-ui.textarea :label="__('Body (English)')" name="body_en" rows="6" required />
                <x-ui.textarea :label="__('Body (Arabic)')" name="body_ar" rows="6" dir="rtl" required />
                <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Pinned')" name="is_pinned" /><x-ui.checkbox :label="__('Published')" name="is_published" :checked="true" /><x-ui.button type="submit">{{ __('Save article') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('New collection')">
            <form method="POST" action="{{ route('admin.cms.help.collections.store') }}" class="flex flex-col gap-3">
                @csrf
                <input type="hidden" name="hub" value="{{ $hub }}">
                <x-ui.select :label="__('Audience')" name="audience" :options="['customer' => __('Customers'), 'seller' => __('Sellers')]" :selected="$hub === 'knowledge_hub' ? 'seller' : 'customer'" />
                <x-ui.input :label="__('Title (English)')" name="title_en" id="collection_title_en" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" id="collection_title_ar" dir="rtl" required />
                <x-ui.input :label="__('Description (English)')" name="description_en" />
                <x-ui.button type="submit" size="sm">{{ __('Add collection') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-layouts.panel>
