@props(['href' => null, 'active' => false, 'count' => null, 'soon' => false])

@if ($soon || $href === null)
    <span aria-disabled="true" title="{{ __('Coming soon') }}" data-nav-soon
          {{ $attributes->class('flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm text-fg-sidebar/50') }}>
        <span class="grow">{{ $slot }}</span>
        <span class="rounded-full border border-fg-sidebar/30 px-2 text-[10px] tracking-wide uppercase">{{ __('Soon') }}</span>
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif
       {{ $attributes->class([
           'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
           'bg-sidebar-active text-white' => $active,
           'text-fg-sidebar hover:bg-sidebar-active/60 hover:text-white' => ! $active,
       ]) }}>
        <span class="grow">{{ $slot }}</span>
        @if ($count)
            <span class="rounded-full bg-brand px-2 text-xs font-semibold text-fg-on-brand">{{ $count }}</span>
        @endif
    </a>
@endif
