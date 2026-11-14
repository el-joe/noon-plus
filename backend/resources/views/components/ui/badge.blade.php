@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-subtle text-fg-secondary',
        'brand' => 'bg-brand-subtle text-fg-brand',
        'success' => 'bg-success-subtle text-fg-success',
        'warning' => 'bg-warning-subtle text-fg-warning',
        'danger' => 'bg-danger-subtle text-fg-danger',
        'info' => 'bg-info-subtle text-fg-info',
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium '.$tones[$tone]) }}>{{ $slot }}</span>
