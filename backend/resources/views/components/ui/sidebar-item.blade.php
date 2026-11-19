@props(['href', 'active' => false, 'count' => null, 'icon' => null, 'variant' => 'leaf'])

{{-- A sidebar link: `top` = top-level entry with an icon, `leaf` = link inside a group (docs/project/panel-navigation.md). --}}
<a href="{{ $href }}" data-nav-link @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'nav-link relative flex items-center gap-3 rounded-lg py-2 pe-3 text-sm outline-none transition-colors focus-visible:ring-2 focus-visible:ring-brand',
       'ps-3' => $variant === 'top',
       'ps-11' => $variant === 'leaf',
       'bg-sidebar-active font-medium text-white before:absolute before:inset-y-1.5 before:start-0 before:w-[3px] before:rounded-full before:bg-brand' => $active,
       'text-fg-sidebar hover:bg-sidebar-active/60 hover:text-white' => ! $active,
   ]) }}>
    @if ($icon)
        <x-icon :name="$icon" />
    @endif
    <span class="grow truncate" data-nav-label>{{ $slot }}</span>
    @if ($count)
        <span class="rounded-full bg-brand px-2 text-xs font-semibold text-fg-on-brand" data-nav-count>{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
