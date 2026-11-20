@php($P = \App\Domains\Content\Enums\PageStatus::class)
@php($templates = collect(\App\Domains\Content\Enums\PageTemplate::cases())->mapWithKeys(fn ($t) => [$t->value => __($t->label())])->all())
@php($chipTones = ['draft' => 'neutral', 'scheduled' => 'info', 'published' => 'success', 'archived' => 'warning'])
<x-layouts.panel :title="__('Pages & page builder')" :subtitle="__(':country · one page per address and language; :count section types', ['country' => $country->name_en, 'count' => count(\App\Domains\Content\Support\BlockRegistry::all())])" :breadcrumbs="[__('Content'), __('Pages')]">
    @include('panels.admin.content._nav')

    {{-- Status chips. --}}
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Filter by status') }}">
        @php($base = collect($filters)->except('status')->filter()->all())
        <a href="{{ route('admin.pages', $base) }}" @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm', 'border-inverse bg-inverse text-fg-inverse' => empty($filters['status']), 'border-line bg-surface hover:bg-subtle' => ! empty($filters['status'])])>
            {{ __('All') }} <span class="text-xs opacity-70">{{ $counts->sum() }}</span>
        </a>
        @foreach ($P::cases() as $status)
            <a href="{{ route('admin.pages', [...$base, 'status' => $status->value]) }}" @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm', 'border-inverse bg-inverse text-fg-inverse' => ($filters['status'] ?? null) === $status->value, 'border-line bg-surface hover:bg-subtle' => ($filters['status'] ?? null) !== $status->value])>
                {{ \App\Shared\Localization\Label::of($status->value) }} <span class="text-xs opacity-70">{{ $counts[$status->value] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <x-ui.card flush>
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4" role="search">
            <div class="flex min-w-56 flex-1 flex-col gap-1.5">
                <label for="pages-q" class="text-sm font-medium">{{ __('Search') }}</label>
                <input id="pages-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Title or address…') }}" class="h-10 rounded-lg border border-line bg-surface px-3 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30">
            </div>
            <x-ui.select :label="__('Template')" name="template" id="pages-template" :options="$templates" :selected="$filters['template'] ?? null" :placeholder="__('All templates')" :use-old="false" />
            @if ($countries->count() > 1)
                <x-ui.select :label="__('Country')" name="country" id="pages-country" :options="$countries->mapWithKeys(fn ($c) => [$c->code => $c->name_en])->all()" :selected="$country->code" :use-old="false" />
            @endif
            <x-ui.select :label="__('Language')" name="locale" id="pages-locale" :options="collect($country->locales)->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all()" :selected="$filters['locale'] ?? null" :placeholder="__('All languages')" :use-old="false" />
            <x-ui.select :label="__('Status')" name="status" id="pages-status" :options="collect($P::cases())->mapWithKeys(fn ($s) => [$s->value => \App\Shared\Localization\Label::of($s->value)])->all()" :selected="$filters['status'] ?? null" :placeholder="__('All statuses')" :use-old="false" />
            <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
            @if (collect($filters)->filter()->isNotEmpty())<a href="{{ route('admin.pages') }}" class="pb-2.5 text-sm text-fg-link">{{ __('Clear') }}</a>@endif
        </form>

        <x-ui.table :headers="[__('Page'), __('Template'), __('Language'), __('Status'), __('Last edited'), __('Actions')]" :rows="$pages->isNotEmpty()" :empty="__('No pages match.')">
            @foreach ($pages as $page)
                <tr class="border-t border-line align-top" data-page="{{ $page->id }}">
                    <td class="px-5 py-3">
                        <a class="font-medium text-fg-link" href="{{ route('admin.pages.show', $page->id) }}">{{ $page->title }}</a>
                        <span class="block text-xs text-fg-secondary" dir="ltr">/{{ $page->slug }}</span>
                    </td>
                    <td class="px-5 py-3">{{ __($page->template->label()) }}</td>
                    <td class="px-5 py-3 uppercase">{{ $page->locale }}</td>
                    <td class="px-5 py-3">
                        <x-ui.status-badge :status="$page->status" />
                        @if ($page->publish_at)<span class="mt-1 block text-xs text-fg-secondary">{{ __('Publishes :at (Oman)', ['at' => $clock->inAdminTime($page->publish_at)->format('j M Y, H:i')]) }}</span>@endif
                        @if ($page->status === $P::Published && $page->current_revision_id !== $page->published_revision_id)<span class="mt-1 block text-xs text-fg-warning">{{ __('Unpublished changes') }}</span>@endif
                    </td>
                    <td class="px-5 py-3 text-xs">
                        {{ $clock->inAdminTime($page->updated_at)->format('j M Y, H:i') }}
                        <span class="block text-fg-secondary">{{ $page->updated_by ? $names->name($page->updated_by) : '—' }} · {{ trans_choice(':count revision|:count revisions', $page->revisions_count) }}</span>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-1">
                            <x-ui.button size="sm" variant="outline" :href="route('admin.pages.show', $page->id)">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" :href="$preview->url($page, $country, $page->locale)" target="_blank" rel="noopener">{{ __('Preview') }}</x-ui.button>
                            @can('pages.manage')
                                <details class="relative">
                                    <summary class="inline-flex h-8 cursor-pointer list-none items-center rounded-lg px-2 text-sm hover:bg-subtle" aria-label="{{ __('More actions') }}">⋯</summary>
                                    <div class="absolute end-0 z-20 mt-1 flex w-80 flex-col gap-3 rounded-xl border border-line bg-surface p-4 text-sm shadow-xl">
                                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'duplicate']) }}" class="flex flex-col gap-2">@csrf
                                            <span class="font-semibold">{{ __('Duplicate as a new page') }}</span>
                                            <input name="slug" value="{{ $page->slug }}-copy" aria-label="{{ __('Address (slug)') }}" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm" dir="ltr" required pattern="[a-z0-9-]+">
                                            <input name="title" value="{{ $page->title }} {{ __('(copy)') }}" aria-label="{{ __('Title') }}" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm" required>
                                            <select name="locale" aria-label="{{ __('Language') }}" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm">@foreach ($country->locales as $locale)<option value="{{ $locale }}" @selected($locale === $page->locale)>{{ strtoupper($locale) }}</option>@endforeach</select>
                                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Duplicate') }}</x-ui.button>
                                        </form>
                                        @if (count($country->locales) > 1)
                                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'locales']) }}" class="flex flex-col gap-2 border-t border-line pt-3">@csrf
                                                <span class="font-semibold">{{ __('Copy the draft to other languages (as drafts)') }}</span>
                                                <div class="flex flex-wrap gap-3">@foreach (array_diff($country->locales, [$page->locale]) as $locale)<label class="flex items-center gap-1.5"><input type="checkbox" name="locales[]" value="{{ $locale }}" class="size-4 accent-brand" checked> {{ strtoupper($locale) }}</label>@endforeach</div>
                                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Copy to languages') }}</x-ui.button>
                                            </form>
                                        @endif
                                        @if ($otherCountries->isNotEmpty())
                                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'copy']) }}" class="flex flex-col gap-2 border-t border-line pt-3">@csrf
                                                <span class="font-semibold">{{ __('Copy the draft to other countries (as drafts)') }}</span>
                                                <div class="grid grid-cols-2 gap-1.5">@foreach ($otherCountries as $other)<label class="flex items-center gap-1.5"><input type="checkbox" name="countries[]" value="{{ $other->code }}" class="size-4 accent-brand"> {{ $other->name_en }}</label>@endforeach</div>
                                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Copy to countries') }}</x-ui.button>
                                            </form>
                                        @endif
                                        <div class="flex flex-wrap gap-2 border-t border-line pt-3">
                                            @if (in_array($page->status, [$P::Draft, $P::Published], true))
                                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'archive']) }}" onsubmit="return confirm(@js(__('Archive this page? It leaves the storefront.')))">@csrf<x-ui.button type="submit" size="sm" variant="outline">{{ __('Archive') }}</x-ui.button></form>
                                            @endif
                                            @if ($page->published_revision_id === null && in_array($page->status, [$P::Draft, $P::Archived], true))
                                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'delete']) }}" onsubmit="return confirm(@js(__('Delete this page and all its draft revisions? This cannot be undone.')))">@csrf<x-ui.button type="submit" size="sm" variant="danger">{{ __('Delete page') }}</x-ui.button></form>
                                            @endif
                                        </div>
                                    </div>
                                </details>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $pages->links() }}

    @can('pages.manage')
        <x-ui.card :title="__('New page')">
            <form method="POST" action="{{ route('admin.pages.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.select :label="__('Template')" name="template" :options="$templates" />
                <x-ui.input :label="__('Address (slug)')" name="slug" :helper="__('Lower-case letters, digits and dashes, e.g. home or electronics')" required />
                <x-ui.select :label="__('Language')" name="locale" :options="collect($country->locales)->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all()" />
                <x-ui.input :label="__('Title')" name="title" required />
                <x-ui.select :label="__('Start from layout')" name="layout_id" :options="$layouts->mapWithKeys(fn ($l) => [$l->id => $l->name.' ('.__($l->template->label()).')'])->all()" :placeholder="__('Empty page')" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Create draft') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
