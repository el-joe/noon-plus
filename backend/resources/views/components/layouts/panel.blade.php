@props(['panel', 'title', 'subtitle' => null, 'navigation' => [], 'breadcrumbs' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Nawy {{ $panel->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-page font-sans text-fg antialiased">
<div class="flex min-h-screen">
    <aside class="hidden w-64 shrink-0 flex-col gap-1 bg-sidebar px-4 py-5 lg:flex" aria-label="{{ __('Main navigation') }}">
        <div class="mb-4 flex items-center gap-2 px-2">
            <span class="grid size-9 place-items-center rounded-lg bg-brand font-bold text-fg-on-brand">N</span>
            <span class="flex flex-col leading-tight">
                <span class="font-bold text-white">Nawy</span>
                <span class="text-xs text-fg-sidebar">{{ $panel->name }}</span>
            </span>
        </div>
        <nav class="flex flex-col gap-1">
            @foreach ($navigation as $group)
                <p class="mt-3 px-3 text-[11px] font-semibold tracking-wider text-fg-sidebar/70 uppercase">{{ $group['label'] }}</p>
                @foreach ($group['items'] as $item)
                    <x-ui.sidebar-item :href="$item['href']" :active="$item['active'] ?? false" :count="$item['count'] ?? null">{{ $item['label'] }}</x-ui.sidebar-item>
                @endforeach
            @endforeach
        </nav>
    </aside>

    <div class="flex min-w-0 grow flex-col">
        <header class="flex h-16 items-center gap-4 border-b border-line bg-surface px-6">
            <div class="grow"></div>
            <span class="text-sm text-fg-secondary">{{ auth()->user()?->name }}</span>
            <form method="POST" action="{{ route($panel->route('logout')) }}">
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Sign out') }}</x-ui.button>
            </form>
        </header>

        <main class="flex flex-col gap-6 px-8 py-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    @if ($breadcrumbs)
                        <nav class="text-xs text-fg-secondary" aria-label="{{ __('Breadcrumb') }}">{{ implode(' › ', $breadcrumbs) }}</nav>
                    @endif
                    <h1 class="text-2xl font-bold">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="text-sm text-fg-secondary">{{ $subtitle }}</p>
                    @endif
                </div>
                @isset($actions)
                    <div class="flex gap-2">{{ $actions }}</div>
                @endisset
            </div>

            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
