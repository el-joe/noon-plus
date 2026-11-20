@props(['panel', 'navigation'])

@php
    $linkCount = collect($navigation)->sum(fn (array $entry): int => count($entry['items']));
    $hasSearch = $linkCount >= 12;
@endphp

{{-- Panel sidebar body: search (Nav/Search 232:302) and accordion groups (docs/design/panel-nav-design.md,
     docs/project/panel-navigation.md, resources/js/panel-sidebar.js). Only the group of the current page is open on load. --}}
<nav class="flex min-h-0 grow flex-col" data-nav data-nav-for="{{ $panel->key }}">
    @if ($hasSearch)
        <div class="nav-tools px-3 pb-2">
            <div class="flex h-9 items-center gap-2 rounded-lg bg-sidebar-hover px-2.5 ring-1 ring-sidebar-active ring-inset focus-within:ring-2 focus-within:ring-brand">
                <x-icon name="search" :size="16" class="text-fg-sidebar-muted" />
                <label for="nav-search" class="sr-only">{{ __('Search menu') }}</label>
                <input id="nav-search" type="text" role="searchbox" autocomplete="off" spellcheck="false" data-nav-search
                       placeholder="{{ __('Search menu') }}" aria-keyshortcuts="/" aria-describedby="nav-search-hint"
                       class="h-full min-w-0 grow bg-transparent text-sm text-white outline-none placeholder:text-fg-sidebar-muted">
                <kbd class="grid h-5 min-w-5 place-items-center rounded px-1.5 font-sans text-[11px]/[14px] text-fg-sidebar-muted ring-1 ring-sidebar-line ring-inset"
                     data-nav-search-kbd aria-hidden="true">/</kbd>
            </div>
            <span id="nav-search-hint" class="sr-only">{{ __('Type to filter the menu; Enter opens the first match, Esc clears.') }}</span>
        </div>
        <p class="nav-results hidden px-4 pb-1 text-xs text-fg-sidebar-muted" data-nav-results role="status" aria-live="polite"
           data-template="{{ __(':results in :groups · Enter opens the first · Esc clears') }}"
           data-result-one="{{ __(':count result') }}" data-result-many="{{ __(':count results') }}"
           data-group-one="{{ __(':count group') }}" data-group-many="{{ __(':count groups') }}"
           data-empty="{{ __('No pages match') }}"></p>
    @endif

    <div @class(['nav-scroll min-h-0 grow overflow-y-auto px-3 pb-3', 'pt-1' => $hasSearch, 'pt-2' => ! $hasSearch])>
        <ul class="flex flex-col gap-0.5" role="list" data-nav-list>
            @foreach ($navigation as $index => $entry)
                {{-- Divider after the top-level links and before the bottom links. --}}
                @if ($index > 0 && ($navigation[$index - 1]['type'] === 'link') !== ($entry['type'] === 'link'))
                    <li class="nav-divider mx-2.5 my-2 h-px bg-sidebar-hover" role="separator" data-nav-divider></li>
                @endif
                @if ($entry['type'] === 'link')
                    @php($link = $entry['items'][0])
                    <li data-nav-entry data-nav-type="link" data-nav-text="{{ $link['label'] }}">
                        <x-ui.sidebar-item variant="top" :href="$link['href']" :active="$link['active']" :count="$link['count']" :icon="$entry['icon']">{{ $link['label'] }}</x-ui.sidebar-item>
                    </li>
                @else
                    <li data-nav-entry data-nav-type="group" data-nav-group="{{ $entry['key'] }}" data-nav-text="{{ $entry['label'] }}"
                        @if ($entry['active']) data-nav-active data-open @endif>
                        <button type="button" id="nav-button-{{ $entry['key'] }}" class="nav-row nav-toggle" data-nav-toggle
                                aria-expanded="{{ $entry['active'] ? 'true' : 'false' }}" aria-controls="nav-group-{{ $entry['key'] }}">
                            <x-icon :name="$entry['icon']" class="nav-icon" />
                            <span class="nav-label" data-nav-label>{{ $entry['label'] }}</span>
                            @if ($entry['count'])
                                <span class="nav-badge nav-badge--neutral" data-nav-group-count>{{ $entry['count'] > 99 ? '99+' : $entry['count'] }}</span>
                                <span class="sr-only">{{ trans_choice(':count item waiting|:count items waiting', $entry['count'], ['count' => $entry['count']]) }}</span>
                                <span class="nav-dot" aria-hidden="true"></span>
                            @endif
                            <x-icon name="chevron-right" :size="16" class="nav-chevron" data-nav-chevron />
                        </button>
                        <div id="nav-group-{{ $entry['key'] }}" class="nav-collapse" data-nav-panel role="group" aria-labelledby="nav-button-{{ $entry['key'] }}">
                            <div class="nav-collapse-inner">
                                <p class="nav-flyout-title px-2.5 pt-2 pb-1.5 text-[11px]/[14px] font-medium tracking-[.06em] text-fg-sidebar-muted uppercase rtl:text-[11px]/4 rtl:tracking-normal rtl:normal-case" aria-hidden="true">{{ $entry['label'] }}</p>
                                <ul class="flex flex-col gap-0.5 pt-0.5 pb-1.5" role="list">
                                    @foreach ($entry['items'] as $item)
                                        <li data-nav-item data-nav-text="{{ $item['label'] }}">
                                            <x-ui.sidebar-item :href="$item['href']" :active="$item['active']" :count="$item['count']">{{ $item['label'] }}</x-ui.sidebar-item>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
</nav>
<noscript><style>.nav-collapse{grid-template-rows:1fr!important;visibility:visible!important}.nav-chevron,.nav-tools{display:none}</style></noscript>
