@props(['panel' => null, 'title', 'subtitle' => null, 'navigation' => null, 'breadcrumbs' => []])

@php
    $panel ??= \App\Domains\Panels\Support\Panel::fromRequest(request());
    $navigation ??= \App\Domains\Panels\Navigation\PanelNavigation::for($panel, (string) request()->route()?->getName());
    $panelCountries ??= collect();
    $currentCountry ??= null;
@endphp

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
                    <x-ui.sidebar-item :href="$item['href']" :active="$item['active'] ?? false" :count="$item['count'] ?? null" :soon="$item['soon'] ?? false">{{ $item['label'] }}</x-ui.sidebar-item>
                @endforeach
            @endforeach
        </nav>
    </aside>

    <div class="flex min-w-0 grow flex-col">
        <header class="flex h-16 items-center gap-4 border-b border-line bg-surface px-6">
            <div class="grow"></div>
            @if ($currentCountry && $panelCountries->count() > 1 && Route::has($panel->route('country')))
                <form method="POST" action="{{ route($panel->route('country')) }}" class="flex items-center gap-2" data-country-switcher>
                    @csrf
                    <label for="panel-country" class="sr-only">{{ __('Country') }}</label>
                    <select id="panel-country" name="country" onchange="this.form.submit()"
                            class="h-9 rounded-lg border border-line bg-surface px-3 text-sm">
                        @foreach ($panelCountries as $country)
                            <option value="{{ $country->code }}" @selected($country->is($currentCountry))>{{ $country->name_en }} · {{ $country->currency }}</option>
                        @endforeach
                    </select>
                    <noscript><x-ui.button type="submit" variant="outline" size="sm">{{ __('Switch') }}</x-ui.button></noscript>
                </form>
            @elseif ($currentCountry)
                <x-ui.badge tone="neutral">{{ $currentCountry->name_en }} · {{ $currentCountry->currency }}</x-ui.badge>
            @endif
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

            @if (session('status'))
                <div role="status" class="rounded-lg border border-line bg-success-subtle px-4 py-3 text-sm text-fg-success">{{ session('status') }}</div>
            @endif
            @error('action')
                <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ $message }}</div>
            @enderror

            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
