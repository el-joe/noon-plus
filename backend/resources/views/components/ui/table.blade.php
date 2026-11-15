@props(['headers' => [], 'empty' => null, 'rows' => true])

<div class="overflow-x-auto">
    <table {{ $attributes->class('w-full text-sm') }}>
        <thead class="bg-subtle text-xs text-fg-secondary">
        <tr>
            @foreach ($headers as $header)
                <th class="px-5 py-2 text-start font-medium whitespace-nowrap">{{ $header }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        {{ $slot }}
        @if (! $rows)
            <tr><td colspan="{{ count($headers) }}" class="px-5 py-8 text-center text-fg-secondary">{{ $empty ?? __('Nothing here yet.') }}</td></tr>
        @endif
        </tbody>
    </table>
</div>
