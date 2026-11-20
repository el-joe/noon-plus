@php
    $P = \App\Domains\Content\Enums\PageStatus::class;
    $templates = collect(\App\Domains\Content\Enums\PageTemplate::cases())->mapWithKeys(fn ($t) => [$t->value => __($t->label())])->all();
    $base = collect($filters)->except('status')->filter()->all();
    $chip = function ($page) use ($P): array {
        return match (true) {
            $page->status === $P::Published && $page->current_revision_id !== $page->published_revision_id => [__('Published · edited'), 'bg-info-subtle text-fg-info'],
            $page->status === $P::Published => [__('Published'), 'bg-success-subtle text-fg-success'],
            $page->status === $P::Scheduled => [__('Scheduled'), 'bg-info-subtle text-fg-info'],
            $page->status === $P::Archived => [__('Archived'), 'bg-subtle text-fg-secondary'],
            default => [__('Draft'), 'bg-warning-subtle text-fg-warning'],
        };
    };
    $filterSelect = 'h-[38px] appearance-none rounded-lg border border-line bg-surface ps-3 pe-8 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30';
@endphp
<x-layouts.panel :title="__('Pages')" :subtitle="__('Storefront pages built with blocks · :country · times in Oman', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('Pages')]">
    @include('panels.admin.content._nav')

    {{-- Pages list (Figma 253:5141). --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2" role="search" data-pages-filters>
            <label class="relative">
                <span class="sr-only">{{ __('Search') }}</span>
                <svg aria-hidden="true" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Search title or address…') }}" class="h-[38px] w-72 rounded-lg border border-line bg-surface ps-9 pe-3 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30">
            </label>
            @foreach ([
                'template' => [__('Template'), $templates, $filters['template'] ?? '', __('All')],
                'locale' => [__('Locale'), collect($country->locales)->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all(), $filters['locale'] ?? '', implode(' + ', array_map('strtoupper', $country->locales))],
                'status' => [__('Status'), collect($P::cases())->mapWithKeys(fn ($s) => [$s->value => \App\Shared\Localization\Label::of($s->value)])->all(), $filters['status'] ?? '', __('Any')],
            ] as $name => [$label, $options, $selected, $all])
                <label class="relative flex items-center">
                    <span class="pointer-events-none absolute start-3 text-sm text-fg-secondary">{{ $label }}:</span>
                    <select name="{{ $name }}" onchange="this.form.submit()" class="{{ $filterSelect }}" style="padding-inline-start: calc({{ mb_strlen($label) }}ch + 1.5rem)">
                        <option value="">{{ $all }}</option>
                        @foreach ($options as $value => $text)<option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>@endforeach
                    </select>
                    <svg aria-hidden="true" class="pointer-events-none absolute end-2.5 size-4 text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m6 9 6 6 6-6"/></svg>
                </label>
                @if ($name === 'template' && $countries->count() > 1)
                    <label class="relative flex items-center">
                        <span class="pointer-events-none absolute start-3 text-sm text-fg-secondary">{{ __('Country') }}:</span>
                        <select name="country" onchange="this.form.submit()" class="{{ $filterSelect }}" style="padding-inline-start: calc({{ mb_strlen(__('Country')) }}ch + 1.5rem)">
                            @foreach ($countries as $option)<option value="{{ $option->code }}" @selected($option->is($country))>{{ strtoupper($option->code) }}</option>@endforeach
                        </select>
                        <svg aria-hidden="true" class="pointer-events-none absolute end-2.5 size-4 text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m6 9 6 6 6-6"/></svg>
                    </label>
                @endif
            @endforeach
            <noscript><x-ui.button type="submit" size="sm" variant="outline">{{ __('Filter') }}</x-ui.button></noscript>
            @if (collect($filters)->filter()->isNotEmpty())<a href="{{ route('admin.pages') }}" class="ms-2 text-sm text-fg-link">{{ __('Clear filters') }}</a>@endif
        </form>
        @can('pages.manage')
            <a href="#new-page" class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-fg-on-brand hover:bg-brand-hover">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5v14"/></svg>{{ __('New page') }}
            </a>
        @endcan
    </div>

    {{-- Status tabs with counts. --}}
    <nav class="flex gap-1 border-b border-line" aria-label="{{ __('Filter by status') }}">
        @foreach ([null => __('All'), ...collect($P::cases())->mapWithKeys(fn ($s) => [$s->value => \App\Shared\Localization\Label::of($s->value)])->all()] as $value => $label)
            @php($active = ($filters['status'] ?? null) == ($value ?: null))
            <a href="{{ route('admin.pages', $value ? [...$base, 'status' => $value] : $base) }}" @class(['-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm', 'border-brand font-medium text-fg' => $active, 'border-transparent text-fg-secondary hover:text-fg' => ! $active])>
                {{ $label }} <span class="rounded-full bg-subtle px-1.5 text-xs text-fg-secondary">{{ $value ? ($counts[$value] ?? 0) : $counts->sum() }}</span>
            </a>
        @endforeach
    </nav>

    <div class="overflow-hidden rounded-xl border border-line bg-surface">
        <table class="w-full text-sm">
            <thead class="bg-page text-start text-xs text-fg-secondary">
                <tr>
                    @foreach ([__('Page'), __('Template'), __('Country'), __('Locales'), __('Status'), __('Sections'), __('Last edited'), ''] as $heading)
                        <th class="px-4 py-3 text-start font-medium">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($pages as $page)
                    @php([$chipLabel, $chipClass] = $chip($page))
                    @php($sections = collect($page->currentRevision?->zones ?? [])->sum(fn ($blocks) => count((array) $blocks)))
                    <tr class="group border-t border-line hover:bg-page" data-page="{{ $page->id }}">
                        <td class="px-4 py-3">
                            <a class="text-[15px] font-medium text-fg hover:text-fg-link" href="{{ route('admin.pages.show', $page->id) }}">{{ $page->title }}</a>
                            <span class="block text-xs text-fg-secondary" dir="ltr">/{{ $country->marketSlug($page->locale) }}{{ $page->template === \App\Domains\Content\Enums\PageTemplate::Home ? '' : '/'.$page->slug }}</span>
                        </td>
                        <td class="px-4 py-3 text-fg-secondary">{{ __($page->template->label()) }}</td>
                        <td class="px-4 py-3 text-fg-secondary">{{ strtoupper($country->code) }}</td>
                        <td class="px-4 py-3">
                            <span class="flex gap-1">@foreach ($localesBySlug[$page->slug] ?? [$page->locale] as $locale)<span @class(['rounded-md border px-1.5 text-xs font-medium', 'border-line-strong bg-subtle' => $locale === $page->locale, 'border-line text-fg-secondary' => $locale !== $page->locale])>{{ strtoupper($locale) }}</span>@endforeach</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-md px-1.5 py-0.5 text-xs font-medium {{ $chipClass }}">{{ $chipLabel }}@if ($page->status === $P::Scheduled && $page->publish_at) · {{ $clock->inAdminTime($page->publish_at)->format('j M') }}@endif</span>
                        </td>
                        <td class="px-4 py-3 text-fg-secondary">{{ $sections }}</td>
                        <td class="px-4 py-3 text-fg-secondary">
                            {{ $page->updated_by ? $names->name($page->updated_by) : __('System') }} · {{ $clock->inAdminTime($page->updated_at)->format('j M, H:i') }} {{ __('Oman') }}
                            <span class="block text-xs">{{ trans_choice(':count revision|:count revisions', $page->revisions_count) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('admin.pages.show', $page->id) }}" class="inline-flex h-8 items-center rounded-lg bg-inverse px-3 text-xs font-semibold text-fg-inverse opacity-0 group-hover:opacity-100 focus:opacity-100">{{ __('Edit') }}</a>
                                <a href="{{ $preview->url($page, $country, $page->locale) }}" target="_blank" rel="noopener" class="grid size-8 place-items-center rounded-lg text-fg-secondary opacity-0 hover:bg-subtle group-hover:opacity-100 focus:opacity-100" title="{{ __('Preview (draft)') }}" aria-label="{{ __('Preview (draft)') }}">
                                    <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <details class="relative">
                                    <summary class="grid size-8 cursor-pointer list-none place-items-center rounded-lg text-fg-secondary hover:bg-subtle [&::-webkit-details-marker]:hidden" aria-label="{{ __('More actions') }}">
                                        <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                    </summary>
                                    <div class="absolute end-0 z-20 mt-1 flex w-60 flex-col rounded-xl border border-line bg-surface p-1.5 text-sm shadow-xl">
                                        <a href="{{ route('admin.pages.show', $page->id) }}" class="rounded-lg px-2.5 py-2 hover:bg-page">{{ __('Edit in page builder') }}</a>
                                        <a href="{{ $preview->url($page, $country, $page->locale) }}" target="_blank" rel="noopener" class="rounded-lg px-2.5 py-2 hover:bg-page">{{ __('Preview (draft)') }}</a>
                                        @can('pages.manage')
                                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'duplicate']) }}">@csrf
                                                <input type="hidden" name="slug" value="{{ $page->slug }}-copy"><input type="hidden" name="locale" value="{{ $page->locale }}"><input type="hidden" name="title" value="{{ __('Copy of :title', ['title' => $page->title]) }}">
                                                <button type="submit" class="w-full rounded-lg px-2.5 py-2 text-start hover:bg-page">{{ __('Duplicate') }}</button>
                                            </form>
                                            @if ($otherCountries->isNotEmpty())
                                                <button type="button" class="rounded-lg px-2.5 py-2 text-start hover:bg-page" onclick="this.closest('details').open=false; document.getElementById('copy-{{ $page->id }}').showModal()">{{ __('Copy to other countries…') }}</button>
                                            @endif
                                            <a href="{{ route('admin.pages.show', $page->id) }}#history" class="rounded-lg px-2.5 py-2 hover:bg-page">{{ __('Revision history') }}</a>
                                            @if (in_array($page->status, [$P::Draft, $P::Published], true))
                                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'archive']) }}" onsubmit="return confirm(@js(__('Archive this page? It leaves the storefront.')))">@csrf<button type="submit" class="w-full rounded-lg px-2.5 py-2 text-start text-fg-danger hover:bg-danger-subtle">{{ __('Archive') }}</button></form>
                                            @endif
                                            @if ($page->published_revision_id === null && in_array($page->status, [$P::Draft, $P::Archived], true))
                                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'delete']) }}" onsubmit="return confirm(@js(__('Delete this page and all its draft revisions? This cannot be undone.')))">@csrf<button type="submit" class="w-full rounded-lg px-2.5 py-2 text-start text-fg-danger hover:bg-danger-subtle">{{ __('Delete page') }}</button></form>
                                            @endif
                                        @endcan
                                    </div>
                                </details>
                            </div>
                            @can('pages.manage')
                                @if ($otherCountries->isNotEmpty())
                                    <dialog id="copy-{{ $page->id }}" class="m-auto w-[min(480px,calc(100vw-2rem))] rounded-2xl border-0 p-0 text-fg shadow-[0_24px_48px_rgba(13,21,38,0.24)] backdrop:bg-[#0F172A]/48">
                                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'copy']) }}" class="flex flex-col gap-4 p-6">@csrf
                                            <h2 class="text-lg font-semibold">{{ __('Copy “:title” to other countries', ['title' => $page->title]) }}</h2>
                                            <p class="text-sm text-fg-secondary">{{ __('Copied as drafts, with all languages of this address.') }}</p>
                                            <div class="grid grid-cols-2 gap-2">@foreach ($otherCountries as $other)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="countries[]" value="{{ $other->code }}" class="size-4 accent-brand"> {{ $other->name_en }}</label>@endforeach</div>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" onclick="this.closest('dialog').close()" class="h-10 rounded-lg border border-line-strong px-4 text-sm font-medium hover:bg-subtle">{{ __('Cancel') }}</button>
                                                <button type="submit" class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-fg-on-brand hover:bg-brand-hover">{{ __('Copy as drafts') }}</button>
                                            </div>
                                        </form>
                                    </dialog>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-fg-secondary">{{ __('No pages match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="flex items-center justify-between border-t border-line px-4 py-3 text-xs text-fg-secondary">
            <span>{{ __('Showing :shown of :total pages', ['shown' => $pages->count(), 'total' => $pages->total()]) }}</span>
        </div>
    </div>
    {{ $pages->links() }}

    @can('pages.manage')
        <x-ui.card :title="__('New page')" id="new-page">
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
