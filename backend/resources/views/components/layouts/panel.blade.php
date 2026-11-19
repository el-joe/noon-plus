@props(['panel' => null, 'title', 'subtitle' => null, 'navigation' => null, 'breadcrumbs' => [], 'errorSummary' => true])

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
    {{-- Icon-rail sidebar remembered per browser (applied before first paint). --}}
    <script>try { if (window.localStorage.getItem('nawy.nav.rail') === '1') { document.documentElement.setAttribute('data-nav-rail', ''); } } catch (error) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-page font-sans text-fg antialiased">
<div class="flex min-h-screen">
    {{-- QC-025: below 1024px the sidebar opens as a drawer from the topbar menu button; on desktop it can shrink to an icon rail. --}}
    <aside id="panel-sidebar" data-panel-sidebar class="panel-sidebar fixed inset-y-0 start-0 z-40 hidden w-72 shrink-0 flex-col gap-4 overflow-y-auto overscroll-contain bg-sidebar px-3 py-5 lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64" aria-label="{{ __('Main navigation') }}">
        <div class="flex items-center gap-2 px-2">
            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand font-bold text-fg-on-brand">N</span>
            <span class="nav-brand-text flex grow flex-col leading-tight">
                <span class="font-bold text-white">Nawy</span>
                <span class="text-xs text-fg-sidebar">{{ $panel->name }}</span>
            </span>
            <button type="button" data-nav-rail-toggle aria-pressed="false"
                    data-label-collapse="{{ __('Collapse sidebar') }}" data-label-expand="{{ __('Expand sidebar') }}"
                    aria-label="{{ __('Collapse sidebar') }}" title="{{ __('Collapse sidebar') }}"
                    class="hidden size-8 shrink-0 place-items-center rounded-lg text-fg-sidebar outline-none hover:bg-sidebar-active/60 hover:text-white focus-visible:ring-2 focus-visible:ring-brand lg:grid">
                <x-icon name="panel-left" :size="18" class="rtl:-scale-x-100" />
            </button>
        </div>
        <x-ui.sidebar-nav :panel="$panel" :navigation="$navigation" />
    </aside>

    <div class="flex min-w-0 grow flex-col">
        <header class="flex h-16 items-center gap-3 border-b border-line bg-surface px-4 lg:px-6">
            <button type="button" class="grid size-9 place-items-center rounded-lg border border-line lg:hidden" data-sidebar-toggle aria-controls="panel-sidebar" aria-expanded="false">
                <span class="sr-only">{{ __('Open menu') }}</span>
                <svg aria-hidden="true" viewBox="0 0 20 20" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
            </button>
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
            @if (Route::has($panel->route('locale')))
                <form method="POST" action="{{ route($panel->route('locale')) }}" class="flex items-center gap-2" data-locale-switcher>
                    @csrf
                    <label for="panel-locale" class="sr-only">{{ __('Language') }}</label>
                    <select id="panel-locale" name="locale" onchange="this.form.submit()"
                            class="h-9 rounded-lg border border-line bg-surface px-3 text-sm">
                        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
                        <option value="ar" @selected(app()->getLocale() === 'ar')>العربية</option>
                    </select>
                    <noscript><x-ui.button type="submit" variant="outline" size="sm">{{ __('Switch') }}</x-ui.button></noscript>
                </form>
            @endif
            @php($signedIn = auth()->user())
            @if ($signedIn && Route::has($panel->route('notifications')))
                @php($unreadNotifications = app(\App\Domains\Panels\Support\PanelNotifications::class)->unreadCount($signedIn->id, $panel->key))
                {{-- QC-041: notification bell. --}}
                <a href="{{ route($panel->route('notifications')) }}" class="relative grid size-9 place-items-center rounded-lg border border-line" data-panel-bell>
                    <span class="sr-only">{{ __('Notifications') }}</span>
                    <svg aria-hidden="true" viewBox="0 0 20 20" class="size-5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3a5 5 0 0 0-5 5v3l-1.5 2.5h13L15 11V8a5 5 0 0 0-5-5ZM8 16a2 2 0 0 0 4 0"/></svg>
                    @if ($unreadNotifications > 0)
                        <span class="absolute -end-1 -top-1 min-w-5 rounded-full bg-danger px-1 text-center text-[10px] font-semibold text-white" data-unread-count>{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                    @endif
                </a>
            @endif
            <span class="hidden items-center gap-2 sm:flex" data-panel-user>
                <span class="grid size-8 place-items-center rounded-full bg-subtle text-xs font-semibold" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $signedIn?->name, 0, 1)) }}</span>
                <span class="flex flex-col leading-tight">
                    <span class="text-sm">{{ $signedIn?->name }}</span>
                    @if (Route::has($panel->route('security')))
                        <a href="{{ route($panel->route('security')) }}" class="text-xs text-fg-secondary hover:underline">{{ __('Sign-in history') }}</a>
                    @else
                        <span class="text-xs text-fg-secondary">{{ $panel->name }}</span>
                    @endif
                </span>
            </span>
            <form method="POST" action="{{ route($panel->route('logout')) }}">
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Sign out') }}</x-ui.button>
            </form>
        </header>

        <main class="flex flex-col gap-6 px-4 py-6 lg:px-8">
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
            {{-- Browser test BT-ADM-001: many list rows carry small forms (reason + button) with no room for an
                 inline message, so a refused submit used to look like nothing happened. Every other error is
                 summarised here; pages with their own summary pass :error-summary="false". --}}
            @if ($errorSummary && $errors->hasAny(array_values(array_diff($errors->keys(), ['action']))))
                <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger" data-error-summary>
                    @foreach (collect($errors->getMessages())->except('action')->flatten()->unique() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
