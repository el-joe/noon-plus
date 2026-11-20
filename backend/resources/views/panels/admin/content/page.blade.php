@php
    $P = \App\Domains\Content\Enums\PageStatus::class;
    $otherLocales = array_values(array_diff($country->locales, [$page->locale]));
    $edited = $page->status === $P::Published && $page->current_revision_id !== $page->published_revision_id;
    [$chipLabel, $chipClass] = match (true) {
        $page->status === $P::Published && $edited => [__('Published · edited'), 'bg-info-subtle text-fg-info'],
        $page->status === $P::Published => [__('Published'), 'bg-success-subtle text-fg-success'],
        $page->status === $P::Scheduled => [__('Scheduled'), 'bg-info-subtle text-fg-info'],
        $page->status === $P::Archived => [__('Archived'), 'bg-subtle text-fg-secondary'],
        default => [__('Draft'), 'bg-warning-subtle text-fg-warning'],
    };
    $market = $country->marketSlug($page->locale);
    $path = $page->template === \App\Domains\Content\Enums\PageTemplate::Home ? '' : '/'.$page->slug;
    $zoneCount = count($page->template->zones());
    $initials = fn (?string $name): string => collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '·';
    $canManage = auth()->user()?->can('pages.manage') ?? false;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $page->title }} · {{ __('Page builder') }} · Nawy Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-page font-sans text-fg antialiased">
<div data-page-builder class="grid h-screen min-w-[1280px] grid-rows-[56px_minmax(0,1fr)] overflow-hidden">
    <script type="application/json" id="page-builder-state">@json($editor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)</script>
    <script type="application/json" id="page-builder-i18n">@json(\App\Domains\Content\Support\PageBuilderStrings::all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)</script>

    {{-- Top bar (Figma 247:208 / states 251:6345). --}}
    <header class="flex items-center gap-4 border-b border-line bg-surface px-4">
        <a href="{{ route('admin.pages') }}" data-pb-leave-link class="inline-flex items-center gap-1 text-sm text-fg-secondary hover:text-fg">
            <svg aria-hidden="true" class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m15 18-6-6 6-6"/></svg>{{ __('Pages') }}
        </a>
        <span class="h-8 w-px bg-line"></span>
        <div class="flex min-w-0 flex-col">
            <div class="flex min-w-0 items-center gap-2">
                <h1 class="truncate text-[15px] font-semibold" data-pb-title>{{ $page->title }}</h1>
                <span class="shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium {{ $chipClass }}" data-pb-status-chip>{{ $chipLabel }}</span>
            </div>
            <p class="truncate text-xs text-fg-secondary"><span dir="ltr">/{{ $market }}{{ $path }}</span> · {{ __(':template template', ['template' => __($page->template->label())]) }} · {{ trans_choice(':count zone|:count zones', $zoneCount) }}</p>
        </div>
        <details class="relative">
            <summary class="flex h-9 cursor-pointer list-none items-center gap-2 rounded-lg border border-line px-3 text-sm hover:bg-subtle">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/></svg>
                {{ strtoupper($country->code) }} · {{ collect($country->locales)->map(fn ($l) => strtoupper($l))->implode(' / ') }}
                <svg aria-hidden="true" class="size-4 text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="absolute start-0 z-40 mt-1 flex w-72 flex-col gap-1 rounded-xl border border-line bg-surface p-2 text-sm shadow-xl">
                <p class="px-2 pt-1 text-xs text-fg-secondary">{{ __('This page in other languages') }}</p>
                @foreach ($country->locales as $locale)
                    @if ($locale === $page->locale)
                        <span class="flex items-center justify-between rounded-lg bg-brand-subtle px-2 py-1.5 font-medium">{{ strtoupper($locale) }} <span class="text-xs text-fg-secondary">{{ __('Editing') }}</span></span>
                    @elseif (isset($siblings[$locale]))
                        <a href="{{ route('admin.pages.show', $siblings[$locale]) }}" data-pb-leave-link class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-subtle">{{ strtoupper($locale) }} <span class="text-xs text-fg-link">{{ __('Open') }}</span></a>
                    @elseif ($canManage)
                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'locales']) }}" data-pb-requires-save class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-subtle">@csrf
                            <input type="hidden" name="locales[]" value="{{ $locale }}">
                            <span>{{ strtoupper($locale) }}</span><button type="submit" class="text-xs text-fg-link">{{ __('Create from this draft') }}</button>
                        </form>
                    @endif
                @endforeach
                <p class="px-2 pt-2 text-xs text-fg-secondary">{{ __('Other countries: Page settings → Copy & reuse.') }}</p>
            </div>
        </details>

        <div class="ms-auto flex items-center gap-2">
            <span class="max-w-64 truncate text-xs text-fg-secondary" data-pb-status role="status"></span>
            <button type="button" data-pb-undo class="grid size-9 place-items-center rounded-lg text-fg-secondary hover:bg-subtle hover:text-fg disabled:opacity-30" title="{{ __('Undo') }} (Ctrl+Z)" aria-label="{{ __('Undo') }}" disabled>
                <svg aria-hidden="true" class="size-[18px] rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
            </button>
            <button type="button" data-pb-redo class="grid size-9 place-items-center rounded-lg text-fg-secondary hover:bg-subtle hover:text-fg disabled:opacity-30" title="{{ __('Redo') }} (Ctrl+Shift+Z)" aria-label="{{ __('Redo') }}" disabled>
                <svg aria-hidden="true" class="size-[18px] rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg>
            </button>
            <button type="button" data-pb-open-panel="history" class="inline-flex h-10 items-center gap-2 rounded-lg px-3 text-sm font-medium hover:bg-subtle">
                <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>{{ __('History') }}
            </button>
            <a href="{{ $editor['previewUrls'][$page->locale] }}" target="_blank" rel="noopener" data-pb-preview-tab class="inline-flex h-10 items-center gap-2 rounded-lg border border-line-strong bg-surface px-3 text-sm font-medium hover:bg-subtle">
                <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>{{ __('Preview') }}
            </a>
            @if ($canManage)
                <button type="button" data-pb-save class="inline-flex h-10 items-center rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium hover:bg-subtle disabled:opacity-50">{{ __('Save draft') }}</button>
                <button type="button" data-pb-issues hidden class="inline-flex h-10 items-center gap-2 rounded-lg border border-line-danger bg-surface px-3 text-sm font-medium text-fg-danger hover:bg-danger-subtle">
                    <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg><span data-pb-issues-label></span>
                </button>
                @if ($page->status !== $P::Archived)
                    <div class="flex h-10 overflow-hidden rounded-lg bg-brand text-fg-on-brand has-[button:disabled]:opacity-50" data-pb-publish-group>
                        <button type="button" data-pb-publish="now" class="inline-flex items-center gap-2 px-4 text-sm font-semibold hover:bg-brand-hover disabled:cursor-not-allowed">
                            <svg aria-hidden="true" class="size-[18px] rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14.54 21.69a.5.5 0 0 0 .94-.03l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.03.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.94"/></svg>{{ __('Publish') }}
                        </button>
                        <button type="button" data-pb-publish="schedule" class="grid w-10 place-items-center border-s border-fg-on-brand/20 hover:bg-brand-hover disabled:cursor-not-allowed" aria-label="{{ __('Schedule…') }}" title="{{ __('Schedule…') }}">
                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>
                @endif
            @endif
        </div>
    </header>

    <div data-pb-workspace class="grid min-h-0 grid-cols-[280px_minmax(0,1fr)_380px] data-[panel-closed]:grid-cols-[280px_minmax(0,1fr)_0px]">
        {{-- Page outline (245:463 rows). --}}
        <aside class="flex min-h-0 flex-col border-e border-line bg-surface" aria-label="{{ __('Page outline') }}">
            <div class="flex flex-col gap-3 px-4 pt-4 pb-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold">{{ __('Page outline') }}</h2>
                    <button type="button" data-pb-outline-search-toggle class="grid size-8 place-items-center rounded-lg text-fg-secondary hover:bg-subtle" aria-label="{{ __('Search sections') }}">
                        <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>
                <input type="search" data-pb-outline-search hidden placeholder="{{ __('Filter sections…') }}" aria-label="{{ __('Filter sections') }}" class="h-9 rounded-lg border border-line bg-surface px-3 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30">
                @if ($canManage)
                    <button type="button" data-pb-add-section class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-inverse text-sm font-semibold text-fg-inverse hover:opacity-90">
                        <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M5 12h14M12 5v14"/></svg>{{ __('Add section') }}
                    </button>
                @endif
            </div>
            <div data-pb-outline class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 pb-3">
                <p class="p-3 text-sm text-fg-secondary">{{ __('Loading the editor…') }}</p>
                @foreach ($editor['zones'] as $zone => $blocks)
                    <p class="px-3 text-xs text-fg-secondary">{{ __('Zone: :zone', ['zone' => $zone]) }} ({{ count($blocks) }})</p>
                @endforeach
            </div>
            <button type="button" data-pb-open-panel="page" class="flex h-12 shrink-0 items-center gap-2 border-t border-line px-4 text-start text-sm text-fg-secondary hover:bg-subtle hover:text-fg">
                <svg aria-hidden="true" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                <span class="truncate">{{ __('Page settings · SEO, copies, archive') }}</span>
            </button>
        </aside>

        {{-- Preview canvas. --}}
        <main class="relative flex min-h-0 min-w-0 flex-col gap-3 bg-page px-6 pt-3 pb-3" aria-label="{{ __('Live preview') }}">
            <div class="flex flex-wrap items-center gap-3" data-pb-preview>
                <div class="flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="{{ __('Device') }}">
                    @foreach (['desktop' => [__('Desktop'), '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>'], 'tablet' => [__('Tablet'), '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M12 18h.01"/>'], 'mobile' => [__('Mobile'), '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>']] as $device => [$label, $svg])
                        <button type="button" data-pb-device="{{ $device }}" aria-pressed="{{ $device === 'desktop' ? 'true' : 'false' }}" title="{{ $label }}" aria-label="{{ $label }}" class="grid h-8 w-10 place-items-center rounded-md text-fg-secondary hover:text-fg aria-pressed:bg-page aria-pressed:text-fg aria-pressed:shadow-xs aria-pressed:ring-1 aria-pressed:ring-line">
                            <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">{!! $svg !!}</svg>
                        </button>
                    @endforeach
                </div>
                <div class="flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="{{ __('Preview language') }}">
                    @foreach ($country->locales as $locale)
                        <button type="button" data-pb-preview-locale="{{ $locale }}" aria-pressed="{{ $locale === $page->locale ? 'true' : 'false' }}" class="h-8 rounded-md px-3 text-sm text-fg-secondary hover:text-fg aria-pressed:bg-page aria-pressed:font-medium aria-pressed:text-fg aria-pressed:ring-1 aria-pressed:ring-line" @if ($locale === 'ar') lang="ar" @endif>{{ $locale === 'ar' ? 'عربي' : strtoupper($locale) }}</button>
                    @endforeach
                </div>
                <span class="grow"></span>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-fg-secondary">{{ __('Show hidden') }}
                    <input type="checkbox" data-pb-show-hidden role="switch" class="peer sr-only">
                    <span class="relative h-5 w-9 rounded-full bg-line-strong transition peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-line-focus after:absolute after:start-0.5 after:top-0.5 after:size-4 after:rounded-full after:bg-surface after:shadow after:transition peer-checked:after:translate-x-4 rtl:peer-checked:after:-translate-x-4"></span>
                </label>
                <span class="text-sm text-fg-secondary" data-pb-zoom dir="ltr"></span>
                <button type="button" data-pb-preview-refresh class="grid size-8 place-items-center rounded-lg text-fg-secondary hover:bg-surface" title="{{ __('Refresh') }}" aria-label="{{ __('Refresh') }}">
                    <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                </button>
            </div>

            @if (session('status'))
                <div role="status" class="rounded-xl border border-line bg-success-subtle px-4 py-2.5 text-sm text-fg-success" data-pb-flash>{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-xl border border-line-danger bg-danger-subtle px-4 py-2.5 text-sm text-fg-danger">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
            @endif
            <div role="alert" data-pb-problems hidden class="flex items-start gap-3 rounded-xl border border-line-danger bg-danger-subtle px-4 py-3">
                <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-fg-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                <div class="min-w-0 flex-1">
                    <p class="text-[15px] font-semibold text-fg-danger" data-pb-problems-title></p>
                    <p class="truncate text-sm text-fg-secondary" data-pb-problems-list></p>
                </div>
                <button type="button" data-pb-first-issue class="h-9 shrink-0 rounded-lg border border-line-danger bg-surface px-3 text-sm font-medium text-fg-danger hover:bg-danger-subtle">{{ __('Go to first issue') }}</button>
            </div>

            <div data-pb-frame-holder class="relative min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
                <div data-pb-frame-box class="relative mx-auto overflow-hidden rounded-[10px] bg-surface shadow-[0_2px_12px_rgba(13,21,38,0.08)]">
                    <iframe title="{{ __('Storefront preview') }}" data-pb-frame class="absolute top-0 start-0 origin-top-left border-0 bg-surface rtl:origin-top-right" referrerpolicy="no-referrer"></iframe>
                </div>
            </div>

            {{-- Unsaved-changes bar (245:510). --}}
            <div data-pb-savebar hidden class="absolute inset-x-0 bottom-6 z-30 mx-auto flex w-fit max-w-[calc(100%-3rem)] items-center gap-4 rounded-xl bg-inverse py-2.5 ps-4 pe-2.5 text-fg-inverse shadow-[0_24px_48px_rgba(13,21,38,0.24)]">
                <span class="size-2 shrink-0 rounded-full bg-brand"></span>
                <span class="text-[15px] font-medium" data-pb-savebar-text>{{ __('Unsaved changes') }}</span>
                <span class="text-sm opacity-60" data-pb-savebar-meta></span>
                <button type="button" data-pb-discard class="h-9 rounded-lg px-3 text-sm font-medium hover:bg-white/10">{{ __('Discard') }}</button>
                @if ($canManage)<button type="button" data-pb-save class="h-9 rounded-lg bg-brand px-4 text-sm font-semibold text-fg-on-brand hover:bg-brand-hover disabled:opacity-50">{{ __('Save draft') }}</button>@endif
            </div>
        </main>

        {{-- Right panel: section settings (JS), page settings, revision history. --}}
        <aside class="flex min-h-0 min-w-0 flex-col overflow-hidden border-s border-line bg-surface" data-pb-panel aria-label="{{ __('Settings') }}">
            <div data-pb-form class="flex min-h-0 flex-1 flex-col" hidden></div>

            <div data-pb-panel-page hidden class="flex min-h-0 flex-1 flex-col">
                <div class="flex items-start justify-between gap-2 border-b border-line px-4 py-4">
                    <div><h2 class="text-lg font-semibold">{{ __('Page settings') }}</h2><p class="text-xs text-fg-secondary">{{ __('Title, search engines, copies and archive') }}</p></div>
                    <button type="button" data-pb-close-panel class="grid size-8 place-items-center rounded-lg text-fg-secondary hover:bg-subtle" aria-label="{{ __('Close') }}"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-4">
                    <div role="alert" class="rounded-lg bg-danger-subtle px-3 py-2 text-sm text-fg-danger" data-pb-page-errors hidden></div>
                    <section class="flex flex-col gap-4">
                        @foreach ($linked as $other)
                            <fieldset class="flex flex-col gap-3 rounded-xl border border-line p-3" @if ($other->locale === 'ar') dir="rtl" lang="ar" @endif>
                                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-fg-secondary">{{ $other->locale === 'ar' ? 'العربية' : 'English' }} · <span dir="ltr">/{{ $country->marketSlug($other->locale) }}{{ $path }}</span></legend>
                                <x-ui.input :label="__('Title')" :name="'pages['.$other->locale.'][title]'" :id="'pb-title-'.$other->locale" :value="$other->title" data-pb-page="title" :data-pb-page-locale="$other->locale" required />
                                <x-ui.input :label="__('SEO title')" :name="'pages['.$other->locale.'][seo_title]'" :id="'pb-seo-title-'.$other->locale" :value="$other->seo_title" data-pb-page="seo_title" :data-pb-page-locale="$other->locale" />
                                <div class="flex flex-col gap-1.5">
                                    <label for="pb-seo-description-{{ $other->locale }}" class="text-sm font-medium">{{ __('SEO description') }}</label>
                                    <textarea id="pb-seo-description-{{ $other->locale }}" rows="3" data-pb-page="seo_description" data-pb-page-locale="{{ $other->locale }}" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30">{{ $other->seo_description }}</textarea>
                                </div>
                            </fieldset>
                        @endforeach
                        @if ($linked->count() > 1)<p class="text-xs text-fg-secondary">{{ __('The :languages pages of this address share one bilingual layout: saving and publishing apply to all of them.', ['languages' => $linked->map(fn ($p) => strtoupper($p->locale))->implode(' + ')]) }}</p>@endif
                        <x-ui.input :label="__('Revision note (optional)')" name="note" id="pb-page-note" data-pb-note :helper="__('Saved with the next draft revision, e.g. “Ramadan banners”.')" />
                        <dl class="grid grid-cols-2 gap-2 rounded-lg bg-page p-3 text-xs">
                            <dt class="text-fg-secondary">{{ __('Address') }}</dt><dd dir="ltr" class="truncate text-end">/{{ $market }}{{ $path }}</dd>
                            <dt class="text-fg-secondary">{{ __('Template') }}</dt><dd class="text-end">{{ __($page->template->label()) }}</dd>
                            <dt class="text-fg-secondary">{{ __('Language') }}</dt><dd class="text-end">{{ strtoupper($page->locale) }}</dd>
                            <dt class="text-fg-secondary">{{ __('Revisions') }}</dt><dd class="text-end">{{ __('Draft revision :draft · live revision :live', ['draft' => $page->currentRevision?->number ?? '—', 'live' => $page->publishedRevision?->number ?? '—']) }}</dd>
                            @if ($page->publish_at)<dt class="text-fg-secondary">{{ __('Scheduled') }}</dt><dd class="text-end">{{ __('Publishes :at (Oman)', ['at' => $clock->inAdminTime($page->publish_at)->format('j M Y, H:i')]) }}</dd>@endif
                        </dl>
                        @if ($canManage && in_array($page->status, [$P::Scheduled, $P::Published, $P::Archived], true))
                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'draft']) }}" data-pb-leave>@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ $page->status === $P::Scheduled ? __('Unschedule') : ($page->status === $P::Published ? __('Unpublish') : __('Back to draft')) }}</x-ui.button></form>
                        @endif
                    </section>

                    @if ($canManage)
                        <section class="flex flex-col gap-4 border-t border-line pt-5">
                            <h3 class="text-base font-semibold">{{ __('Copy & reuse') }}</h3>
                            @if ($otherLocales !== [])
                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'locales']) }}" class="flex flex-col gap-2" data-pb-requires-save>@csrf
                                    <span class="text-sm font-medium">{{ __('Copy the draft to other languages (as drafts)') }}</span>
                                    <div class="flex flex-wrap gap-3">@foreach ($otherLocales as $locale)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="locales[]" value="{{ $locale }}" class="size-4 accent-brand" checked> {{ strtoupper($locale) }}</label>@endforeach</div>
                                    <x-ui.button type="submit" size="sm" variant="outline" class="self-start">{{ __('Copy to languages') }}</x-ui.button>
                                </form>
                            @endif
                            @if ($countries->isNotEmpty())
                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'copy']) }}" class="flex flex-col gap-2" data-pb-requires-save>@csrf
                                    <span class="text-sm font-medium">{{ __('Copy the draft to other countries (as drafts)') }}</span>
                                    <div class="grid grid-cols-2 gap-2">@foreach ($countries as $other)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="countries[]" value="{{ $other->code }}" class="size-4 accent-brand"> {{ $other->name_en }}</label>@endforeach</div>
                                    <x-ui.button type="submit" size="sm" variant="outline" class="self-start">{{ __('Copy to countries') }}</x-ui.button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'duplicate']) }}" class="flex flex-col gap-3" data-pb-requires-save>@csrf
                                <span class="text-sm font-medium">{{ __('Duplicate as a new page') }}</span>
                                <x-ui.input :label="__('Address (slug)')" name="slug" id="duplicate-slug" :value="$page->slug.'-copy'" required />
                                <div class="grid grid-cols-2 gap-3">
                                    <x-ui.select :label="__('Language')" name="locale" id="duplicate-locale" :options="collect($country->locales)->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all()" :selected="$page->locale" />
                                    <x-ui.input :label="__('Title')" name="title" id="duplicate-title" :value="__('Copy of :title', ['title' => $page->title])" required />
                                </div>
                                <x-ui.button type="submit" size="sm" variant="outline" class="self-start">{{ __('Duplicate') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'layout']) }}" class="flex items-end gap-2" data-pb-requires-save>@csrf
                                <div class="flex-1"><x-ui.input :label="__('Save as layout')" name="name" id="layout-name" /></div><x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button></form>
                        </section>

                        <section class="flex flex-col gap-3 border-t border-line pt-5">
                            <h3 class="text-base font-semibold">{{ __('Archive or delete') }}</h3>
                            <div class="flex flex-wrap gap-2">
                                @if (in_array($page->status, [$P::Draft, $P::Published], true))
                                    <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'archive']) }}" data-pb-leave data-pb-confirm="{{ __('Archive this page? It leaves the storefront.') }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Archive') }}</x-ui.button></form>
                                @endif
                                @if ($page->published_revision_id === null && in_array($page->status, [$P::Draft, $P::Archived], true))
                                    <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'delete']) }}" data-pb-leave data-pb-confirm="{{ __('Delete this page and all its draft revisions? This cannot be undone.') }}">@csrf<x-ui.button type="submit" variant="danger" size="sm">{{ __('Delete page') }}</x-ui.button></form>
                                @else
                                    <p class="text-xs text-fg-secondary">{{ __('Pages that were published can only be archived.') }}</p>
                                @endif
                            </div>
                        </section>
                    @endif

                    <section class="border-t border-line pt-5">
                        <x-ui.state-timeline :title="__('Status history')" :steps="$history->map(fn ($row) => ['state' => 'done', 'label' => \App\Shared\Localization\Label::of((string) $row->to_status), 'meta' => ucfirst((string) $row->actor_type).($row->actor_user_id ? ' '.$names->name($row->actor_user_id) : '').' · '.$clock->inAdminTime($row->created_at)->format('j M H:i').($row->reason ? ' · '.$row->reason : '')])->all()" />
                    </section>
                </div>
            </div>

            {{-- Revision history (251:4481). --}}
            <div data-pb-panel-history hidden class="flex min-h-0 flex-1 flex-col">
                <div class="flex items-start justify-between gap-2 border-b border-line px-4 py-4">
                    <div><h2 class="text-lg font-semibold">{{ __('Revision history') }}</h2><p class="text-xs text-fg-secondary">{{ __('Autosave every 30 s · restoring copies into a new draft') }}</p></div>
                    <button type="button" data-pb-close-panel class="grid size-8 place-items-center rounded-lg text-fg-secondary hover:bg-subtle" aria-label="{{ __('Close') }}"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                </div>
                <ul class="flex min-h-0 flex-1 flex-col overflow-y-auto" data-pb-revisions>
                    @foreach ($revisions as $revision)
                        @php($author = $revision->created_by ? $names->name($revision->created_by) : __('System'))
                        @php($isCurrent = $revision->id === $page->current_revision_id)
                        @php($isLive = $revision->id === $page->published_revision_id)
                        <li @class(['flex gap-3 border-b border-line px-4 py-3', 'bg-brand-subtle' => $isLive && ! $isCurrent]) data-revision="{{ $revision->number }}" @if ($isCurrent) data-pb-current-revision @endif>
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-subtle text-xs font-semibold text-fg-secondary">{{ $initials($author) }}</span>
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <p class="flex flex-wrap items-center gap-2 text-[15px] font-medium">
                                    {{ $isCurrent ? __('Current draft') : 'v'.$revision->number }}
                                    @if ($isCurrent)<span class="rounded-md bg-warning-subtle px-1.5 text-xs font-medium text-fg-warning">{{ __('Draft') }} · v{{ $revision->number }}</span>@endif
                                    @if ($isLive)<span class="rounded-md bg-success-subtle px-1.5 text-xs font-medium text-fg-success">{{ __('Live') }}</span>@endif
                                </p>
                                <p class="text-xs text-fg-secondary">{{ $clock->inCountry($country, $revision->created_at)->format('j M, H:i') }} {{ strtoupper($country->code) }} · {{ $author }}@if ($revision->note) · {{ $revision->note }}@endif</p>
                                @if ($summaries[$revision->id] ?? null)<p class="text-xs text-fg-secondary">{{ $summaries[$revision->id] }}</p>@endif
                                @if (! $isCurrent && $canManage)
                                    <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'restore']) }}" class="mt-1.5" data-pb-leave data-pb-confirm="{{ __('Restore revision :number as the new draft?', ['number' => $revision->number]) }}">@csrf
                                        <input type="hidden" name="revision" value="{{ $revision->number }}">
                                        <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand px-3 text-xs font-semibold text-fg-on-brand hover:bg-brand-hover">
                                            <svg aria-hidden="true" class="size-3.5 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>{{ __('Restore this version') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    </div>

    {{-- Add section gallery (249:1923). --}}
    <dialog data-pb-gallery class="m-auto h-[min(760px,calc(100vh-4rem))] w-[min(1080px,calc(100vw-4rem))] overflow-hidden rounded-2xl border-0 bg-surface p-0 text-fg shadow-[0_24px_48px_rgba(13,21,38,0.24)] backdrop:bg-[#0F172A]/48" aria-labelledby="pb-gallery-title">
        <div class="flex h-full flex-col">
            <div class="flex items-center gap-4 border-b border-line px-5 py-4">
                <div class="min-w-0 flex-1">
                    <h2 id="pb-gallery-title" class="text-[22px] font-semibold leading-tight">{{ __('Add section') }}</h2>
                    <p class="truncate text-xs text-fg-secondary" data-pb-gallery-zone></p>
                </div>
                <label class="relative w-[360px]">
                    <span class="sr-only">{{ __('Search sections') }}</span>
                    <svg aria-hidden="true" class="pointer-events-none absolute start-3 top-3 size-[18px] text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" data-pb-gallery-search placeholder="{{ __('Search :count section types…', ['count' => count($editor['types'])]) }}" class="h-11 w-full rounded-lg border-2 border-line bg-surface ps-10 pe-10 text-sm focus:border-line-focus focus:outline-none">
                    <kbd class="pointer-events-none absolute end-3 top-3 rounded border border-line bg-page px-1.5 text-xs text-fg-secondary">/</kbd>
                </label>
                <button type="button" data-pb-gallery-close class="grid size-9 place-items-center rounded-lg text-fg-secondary hover:bg-subtle" aria-label="{{ __('Close') }}">
                    <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="grid min-h-0 flex-1 grid-cols-[236px_minmax(0,1fr)]">
                <nav data-pb-gallery-groups class="flex flex-col gap-0.5 overflow-y-auto border-e border-line bg-page p-3" aria-label="{{ __('Section categories') }}"></nav>
                <div data-pb-gallery-grid class="flex flex-col gap-6 overflow-y-auto p-6"></div>
            </div>
            <div class="flex items-center gap-3 border-t border-line px-5 py-3">
                <svg aria-hidden="true" class="size-4 text-fg-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <p class="flex-1 text-xs text-fg-secondary">{{ __('Tip: drag a card onto the outline to place it. Enter adds, Esc closes.') }}</p>
                <button type="button" data-pb-gallery-close class="h-10 rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium hover:bg-subtle">{{ __('Cancel') }}</button>
            </div>
        </div>
    </dialog>

    {{-- Publish / schedule (250:4897). --}}
    @if ($canManage && $page->status !== $P::Archived)
        <dialog data-pb-publish-dialog class="m-auto w-[min(560px,calc(100vw-2rem))] overflow-hidden rounded-2xl border-0 bg-surface p-0 text-fg shadow-[0_24px_48px_rgba(13,21,38,0.24)] backdrop:bg-[#0F172A]/48" aria-labelledby="pb-publish-title">
            <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'publish']) }}" data-pb-publish-form data-publish-url="{{ route('admin.pages.act', [$page->id, 'publish']) }}" data-schedule-url="{{ route('admin.pages.act', [$page->id, 'schedule']) }}">
                @csrf
                <div class="flex flex-col gap-4 px-6 pt-6 pb-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="pb-publish-title" class="text-xl font-semibold">{{ __('Publish “:title”', ['title' => $page->title]) }}</h2>
                            <p class="text-sm text-fg-secondary">{{ $country->name_en }} · <span dir="ltr">/{{ $market }}{{ $path }}</span></p>
                        </div>
                        <button type="button" data-pb-dialog-close class="grid size-8 place-items-center rounded-lg text-fg-secondary hover:bg-subtle" aria-label="{{ __('Close') }}"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                    </div>
                    <label class="flex cursor-pointer gap-3 rounded-xl border border-line p-4 has-[:checked]:border-line-brand has-[:checked]:bg-brand-subtle">
                        <input type="radio" name="pb_mode" value="now" class="mt-1 size-4 accent-brand" checked>
                        <span><span class="block text-[15px] font-medium">{{ __('Publish now') }}</span><span class="text-xs text-fg-secondary">{{ __('Goes live immediately for shoppers in :country.', ['country' => $country->name_en]) }}</span></span>
                    </label>
                    <label @class(['flex gap-3 rounded-xl border border-line p-4 has-[:checked]:border-line-brand has-[:checked]:bg-brand-subtle', 'cursor-pointer' => $page->status === $P::Draft, 'opacity-50' => $page->status !== $P::Draft])>
                        <input type="radio" name="pb_mode" value="schedule" class="mt-1 size-4 accent-brand" @disabled($page->status !== $P::Draft)>
                        <span><span class="block text-[15px] font-medium">{{ __('Schedule') }}</span><span class="text-xs text-fg-secondary">{{ $page->status === $P::Draft ? __('Keep the current live version until the date below.') : __('Unpublish first to schedule this page.') }}</span></span>
                    </label>
                    <div data-pb-schedule-fields hidden>
                        <x-ui.datetime-input :label="__('Starts')" name="publish_at" id="pb-publish-at" :country="$country" />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl bg-page p-4 text-sm">
                        <p class="font-medium">{{ __('Pre-publish checks') }}</p>
                        <ul class="flex flex-col gap-1.5" data-pb-checks></ul>
                    </div>
                    <p class="text-xs text-fg-secondary" data-pb-changes></p>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-page px-6 py-4">
                    <button type="button" data-pb-dialog-close class="h-10 rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium hover:bg-subtle">{{ __('Cancel') }}</button>
                    <button type="submit" data-pb-publish-submit class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-fg-on-brand hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-50">{{ __('Publish now') }}</button>
                </div>
            </form>
        </dialog>
    @endif
</div>
</body>
</html>
