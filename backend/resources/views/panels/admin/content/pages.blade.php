<x-layouts.panel :title="__('Pages & page builder')" :subtitle="__(':country · one page per address and language; 51 block types', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('Pages')]">
    @include('panels.admin.content._nav')

    <x-ui.card flush>
        <x-slot:actions>
            <form method="GET" class="flex items-end gap-2">
                <x-ui.select name="template" :options="collect(\App\Domains\Content\Enums\PageTemplate::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" :selected="$filters['template'] ?? null" :placeholder="__('All templates')" />
                <x-ui.select name="status" :options="collect(\App\Domains\Content\Enums\PageStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" :selected="$filters['status'] ?? null" :placeholder="__('All statuses')" />
                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <x-ui.table :headers="[__('Page'), __('Template'), __('Language'), __('Publishes (Oman)'), __('Status')]" :rows="$pages->isNotEmpty()" :empty="__('No pages yet.')">
            @foreach ($pages as $page)
                <tr class="border-t border-line" data-page="{{ $page->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.pages.show', $page->id) }}">{{ $page->title }}</a><span class="block text-xs text-fg-secondary">/{{ $page->slug }}</span></td>
                    <td class="px-5 py-3">{{ $page->template->label() }}</td>
                    <td class="px-5 py-3 uppercase">{{ $page->locale }}</td>
                    <td class="px-5 py-3 text-xs">{{ $page->publish_at ? $clock->inAdminTime($page->publish_at)->format('j M Y, H:i') : '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$page->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $pages->links() }}

    @can('pages.manage')
        <x-ui.card :title="__('New page')">
            <form method="POST" action="{{ route('admin.pages.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.select :label="__('Template')" name="template" :options="collect(\App\Domains\Content\Enums\PageTemplate::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />
                <x-ui.input :label="__('Address (slug)')" name="slug" :helper="__('Lower-case letters, digits and dashes, e.g. home or electronics')" required />
                <x-ui.select :label="__('Language')" name="locale" :options="collect($country->locales)->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all()" />
                <x-ui.input :label="__('Title')" name="title" required />
                <x-ui.select :label="__('Start from layout')" name="layout_id" :options="$layouts->mapWithKeys(fn ($l) => [$l->id => $l->name.' ('.$l->template->label().')'])->all()" :placeholder="__('Empty page')" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Create draft') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
