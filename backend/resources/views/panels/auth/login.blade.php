<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign in') }} · Nawy {{ $panelName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-page px-4 font-sans text-fg antialiased">
<main class="flex w-full max-w-sm flex-col gap-6 rounded-2xl border border-line bg-surface p-8">
    <div class="flex items-center gap-2">
        <span class="grid size-10 place-items-center rounded-lg bg-inverse font-bold text-brand">N</span>
        <div>
            <p class="font-bold">Nawy {{ $panelName }}</p>
            <p class="text-xs text-fg-secondary">{{ __('Sign in with your work email') }}</p>
        </div>
    </div>
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <x-ui.input :label="__('Email')" name="email" type="email" autocomplete="username" required autofocus />
        <x-ui.input :label="__('Password')" name="password" type="password" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm text-fg-secondary">
            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line accent-brand"> {{ __('Keep me signed in') }}
        </label>
        <x-ui.button type="submit" size="lg">{{ __('Sign in') }}</x-ui.button>
    </form>
</main>
</body>
</html>
