@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'href' => null])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus disabled:cursor-not-allowed disabled:opacity-50';
    $sizes = ['sm' => 'h-8 px-3 text-sm', 'md' => 'h-10 px-4 text-sm', 'lg' => 'h-12 px-6 text-base'];
    $variants = [
        'primary' => 'bg-brand text-fg-on-brand hover:bg-brand-hover',
        'secondary' => 'bg-inverse text-fg-inverse hover:opacity-90',
        'outline' => 'border border-line-strong bg-surface text-fg hover:bg-subtle',
        'ghost' => 'text-fg hover:bg-subtle',
        'danger' => 'bg-danger text-white hover:opacity-90',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
