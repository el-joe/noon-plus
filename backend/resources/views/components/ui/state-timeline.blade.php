@props(['steps', 'title' => null, 'subtitle' => null])

{{-- Figma "State timeline": done steps green, the current one amber, upcoming ones grey. --}}
<div {{ $attributes->class('rounded-xl border border-line bg-surface p-5') }}>
    @if ($title)
        <h2 class="text-base font-semibold">{{ $title }}</h2>
    @endif
    @if ($subtitle)
        <p class="mb-3 text-xs text-fg-secondary">{{ $subtitle }}</p>
    @endif
    <ol class="flex flex-col gap-3" data-state-timeline>
        @foreach ($steps as $step)
            <li class="flex gap-3" data-state="{{ $step['state'] }}">
                <span @class([
                    'mt-1 size-3 shrink-0 rounded-full',
                    'bg-success' => $step['state'] === 'done',
                    'bg-brand' => $step['state'] === 'current',
                    'bg-danger' => $step['state'] === 'stopped',
                    'bg-subtle' => $step['state'] === 'pending',
                ])></span>
                <span class="flex flex-col">
                    <span @class(['text-sm font-medium', 'text-fg-muted' => $step['state'] === 'pending'])>{{ $step['label'] }}</span>
                    <span class="text-xs text-fg-secondary">{{ $step['meta'] ?: '—' }}</span>
                </span>
            </li>
        @endforeach
    </ol>
</div>
