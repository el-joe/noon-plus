<x-layouts.panel :title="__('Footer')" :subtitle="__(':country · storefront footer for :locale', ['country' => $country->name_en, 'locale' => strtoupper($locale)])" :breadcrumbs="[__('Content'), __('Footer')]">
    @include('panels.admin.content._nav')
    <div class="flex gap-2">
        @foreach ($country->locales as $option)
            <x-ui.button :href="route('admin.cms.footer', ['locale' => $option])" size="sm" :variant="$locale === $option ? 'primary' : 'outline'">{{ strtoupper($option) }}</x-ui.button>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.cms.footer.save') }}" class="flex flex-col gap-6">
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}">
        <x-ui.card :title="__('Link columns')" :subtitle="__('One link per line: Label | /path or https://…')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (array_pad($footer['columns'], 4, ['title' => '', 'links' => []]) as $index => $column)
                    <div class="flex flex-col gap-2">
                        <x-ui.input :label="__('Column :n title', ['n' => $index + 1])" :name="'columns['.$index.'][title]'" :id="'column-title-'.$index" :value="$column['title']" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
                        <x-ui.textarea :label="__('Links')" :name="'columns['.$index.'][links]'" :id="'column-links-'.$index" rows="5" :value="collect($column['links'])->map(fn ($link) => $link['label'].' | '.$link['url'])->implode(PHP_EOL)" />
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Social links')">
            <div class="grid gap-4 sm:grid-cols-4">
                @foreach ($networks as $network)
                    <x-ui.input :label="ucfirst($network)" :name="'social['.$network.']'" :id="'social-'.$network" :value="$footer['social'][$network] ?? ''" />
                @endforeach
            </div>
        </x-ui.card>
        <div class="flex items-end gap-3">
            <x-ui.input :label="__('Copyright line')" name="copyright" :value="$footer['copyright']" :dir="$locale === 'ar' ? 'rtl' : 'ltr'" />
            <x-ui.button type="submit">{{ __('Save footer') }}</x-ui.button>
        </div>
    </form>
</x-layouts.panel>
