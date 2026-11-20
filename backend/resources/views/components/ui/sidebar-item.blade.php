@props(['href', 'active' => false, 'count' => null, 'icon' => null, 'variant' => 'child'])

{{-- Nav/Link (Figma 232:152): `top` = top-level row with an icon, `child` = link inside a group. --}}
<a href="{{ $href }}" data-nav-link @if ($active) aria-current="page" @endif
   {{ $attributes->class(['nav-row', 'nav-row--child' => $variant !== 'top']) }}>
    @if ($icon)
        <x-icon :name="$icon" class="nav-icon" />
    @endif
    <span class="nav-label" data-nav-label>{{ $slot }}</span>
    @if ($count)
        <span class="nav-badge" data-nav-count>{{ $count > 99 ? '99+' : $count }}</span>
        @if ($icon)
            <span class="nav-dot" aria-hidden="true"></span>
        @endif
    @endif
</a>
