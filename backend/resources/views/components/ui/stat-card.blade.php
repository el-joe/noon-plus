@props(['label', 'value', 'delta' => null, 'trend' => 'up'])

<div {{ $attributes->class('flex flex-col gap-2 rounded-xl border border-line bg-surface p-5') }}>
    <span class="text-sm text-fg-secondary">{{ $label }}</span>
    <span class="text-2xl font-bold text-fg">{{ $value }}</span>
    @if ($delta)
        <span @class(['text-xs font-medium', 'text-fg-success' => $trend === 'up', 'text-fg-danger' => $trend === 'down'])>
            {{ $trend === 'up' ? '↗' : '↘' }} {{ $delta }}
        </span>
    @endif
</div>
