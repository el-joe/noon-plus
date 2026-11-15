@props(['title' => null, 'subtitle' => null, 'flush' => false])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-line bg-surface') }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-4 pb-3">
            <div>
                @if ($title)
                    <h2 class="text-base font-semibold">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="text-xs text-fg-secondary">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div @class(['px-5 pb-5' => ! $flush])>{{ $slot }}</div>
</section>
