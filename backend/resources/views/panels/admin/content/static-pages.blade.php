<x-layouts.panel :title="__('Static pages')" :subtitle="__(':country · a country page overrides the global one; audience storefront, portal or both', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('Static pages')]">
    @include('panels.admin.content._nav')

    @foreach ($pages as $page)
        <x-ui.card :title="$page->title_en.' · /'.$page->slug" :subtitle="($page->country_id ? $country->name_en : __('Global')).' · '.$page->audience.($page->is_published ? '' : ' · '.__('hidden'))">
            <form method="POST" action="{{ route('admin.cms.static.save') }}" class="grid gap-4 sm:grid-cols-2" data-static-page="{{ $page->id }}">
                @csrf
                <input type="hidden" name="slug" value="{{ $page->slug }}">
                <input type="hidden" name="scope" value="{{ $page->country_id ? 'country' : 'global' }}">
                <x-ui.input :label="__('Title (English)')" name="title_en" :id="'title-en-'.$page->id" :value="$page->title_en" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" :id="'title-ar-'.$page->id" :value="$page->title_ar" dir="rtl" required />
                <x-ui.textarea :label="__('Body (English)')" name="body_en" :id="'body-en-'.$page->id" :value="$page->body_en" rows="5" />
                <x-ui.textarea :label="__('Body (Arabic)')" name="body_ar" :id="'body-ar-'.$page->id" :value="$page->body_ar" rows="5" dir="rtl" />
                <x-ui.input :label="__('SEO title (English)')" name="seo_title_en" :id="'seo-title-en-'.$page->id" :value="$page->seo_title_en" :helper="__('Empty: the page title')" />
                <x-ui.input :label="__('SEO title (Arabic)')" name="seo_title_ar" :id="'seo-title-ar-'.$page->id" :value="$page->seo_title_ar" dir="rtl" />
                <x-ui.textarea :label="__('SEO description (English)')" name="seo_description_en" :id="'seo-desc-en-'.$page->id" :value="$page->seo_description_en" rows="2" />
                <x-ui.textarea :label="__('SEO description (Arabic)')" name="seo_description_ar" :id="'seo-desc-ar-'.$page->id" :value="$page->seo_description_ar" rows="2" dir="rtl" />
                <x-ui.select :label="__('Audience')" name="audience" :id="'audience-'.$page->id" :options="['storefront' => __('Storefront'), 'portal' => __('Portal'), 'both' => __('Both')]" :selected="$page->audience" />
                <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Published')" name="is_published" :id="'published-'.$page->id" :checked="$page->is_published" /><x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endforeach

    <x-ui.card :title="__('New static page or country override')">
        <form method="POST" action="{{ route('admin.cms.static.save') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.input :label="__('Address (slug)')" name="slug" :helper="__('about, terms, privacy, contact, return, shipping, refund…')" required />
            <x-ui.select :label="__('For')" name="scope" :options="['country' => $country->name_en, 'global' => __('All countries (global)')]" />
            <x-ui.input :label="__('Title (English)')" name="title_en" required />
            <x-ui.input :label="__('Title (Arabic)')" name="title_ar" dir="rtl" required />
            <x-ui.textarea :label="__('Body (English)')" name="body_en" rows="5" />
            <x-ui.textarea :label="__('Body (Arabic)')" name="body_ar" rows="5" dir="rtl" />
            <x-ui.input :label="__('SEO title (English)')" name="seo_title_en" />
            <x-ui.input :label="__('SEO title (Arabic)')" name="seo_title_ar" dir="rtl" />
            <x-ui.textarea :label="__('SEO description (English)')" name="seo_description_en" rows="2" />
            <x-ui.textarea :label="__('SEO description (Arabic)')" name="seo_description_ar" rows="2" dir="rtl" />
            <x-ui.select :label="__('Audience')" name="audience" :options="['both' => __('Both'), 'storefront' => __('Storefront'), 'portal' => __('Portal')]" />
            <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Published')" name="is_published" :checked="true" /><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
