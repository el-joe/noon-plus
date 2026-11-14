@props(['href', 'active' => false, 'count' => null])

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
