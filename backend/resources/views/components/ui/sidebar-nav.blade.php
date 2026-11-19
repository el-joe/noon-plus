@props(['panel', 'navigation'])

@php
    $linkCount = collect($navigation)->sum(fn (array $entry): int => count($entry['items']));
@endphp

{{-- Panel sidebar: top-level links and accordion groups (docs/project/panel-navigation.md,
     resources/js/panel-sidebar.js). Only the group of the current page is open on load. --}}
<nav class="flex flex-col gap-2" data-nav data-nav-for="{{ $panel->key }}">
    @if ($linkCount >= 12)
        <div class="nav-tools relative">
            <label for="nav-search" class="sr-only">{{ __('Search menu') }}</label>
            <x-icon name="search" :size="16" class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-fg-sidebar/70" />
            <input id="nav-search" type="search" autocomplete="off" spellcheck="false" data-nav-search
                   placeholder="{{ __('Search menu') }}" aria-keyshortcuts="/" aria-describedby="nav-search-hint"
                   class="h-9 w-full rounded-lg border border-white/10 bg-white/5 ps-9 pe-8 text-sm text-white placeholder:text-fg-sidebar/60 focus:border-brand focus:outline-none">
            <kbd class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 rounded border border-white/15 px-1.5 text-[10px] text-fg-sidebar/70" data-nav-search-kbd aria-hidden="true">/</kbd>
            <span id="nav-search-hint" class="sr-only">{{ __('Type to filter the menu; Enter opens the first match, Esc clears.') }}</span>
        </div>
    @endif

    <ul class="flex flex-col gap-0.5" role="list" data-nav-list>
        @foreach ($navigation as $entry)
            @if ($entry['type'] === 'link')
                @php($link = $entry['items'][0])
                <li data-nav-entry data-nav-type="link" data-nav-text="{{ $link['label'] }}">
                    <x-ui.sidebar-item variant="top" :href="$link['href']" :active="$link['active']" :count="$link['count']" :icon="$entry['icon']">{{ $link['label'] }}</x-ui.sidebar-item>
                </li>
            @else
                <li data-nav-entry data-nav-type="group" data-nav-group="{{ $entry['key'] }}" data-nav-text="{{ $entry['label'] }}"
                    @if ($entry['active']) data-nav-active data-open @endif>
                    <button type="button" id="nav-button-{{ $entry['key'] }}" data-nav-toggle
                            aria-expanded="{{ $entry['active'] ? 'true' : 'false' }}" aria-controls="nav-group-{{ $entry['key'] }}"
                            @class([
                                'nav-toggle relative flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm outline-none transition-colors focus-visible:ring-2 focus-visible:ring-brand',
                                'text-white' => $entry['active'],
                                'text-fg-sidebar hover:bg-sidebar-active/60 hover:text-white' => ! $entry['active'],
                            ])>
                        <x-icon :name="$entry['icon']" />
                        <span class="grow truncate" data-nav-label>{{ $entry['label'] }}</span>
                        @if ($entry['count'])
                            <span class="rounded-full bg-brand px-2 text-xs font-semibold text-fg-on-brand" data-nav-group-count
                                  aria-label="{{ trans_choice(':count item waiting|:count items waiting', $entry['count'], ['count' => $entry['count']]) }}">{{ $entry['count'] > 99 ? '99+' : $entry['count'] }}</span>
                        @endif
                        <x-icon name="chevron-down" :size="16" class="nav-chevron text-fg-sidebar/70" data-nav-chevron />
                    </button>
                    <div id="nav-group-{{ $entry['key'] }}" class="nav-collapse" data-nav-panel role="group" aria-labelledby="nav-button-{{ $entry['key'] }}">
                        <div class="nav-collapse-inner">
                            <p class="nav-flyout-title px-3 pt-1 pb-2 text-xs font-semibold text-white" aria-hidden="true">{{ $entry['label'] }}</p>
                            <ul class="flex flex-col gap-0.5 pt-0.5 pb-1" role="list">
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
    <p class="hidden px-3 py-2 text-sm text-fg-sidebar/80" data-nav-empty role="status">{{ __('No pages match') }}</p>
</nav>
<noscript><style>[data-nav-panel]{grid-template-rows:1fr!important;visibility:visible!important}[data-nav-chevron],.nav-tools{display:none}</style></noscript>
