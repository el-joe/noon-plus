<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Verify it’s you') }} · Nawy {{ $panelName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-page px-4 font-sans text-fg antialiased">
<main class="flex w-full max-w-sm flex-col gap-6 rounded-2xl border border-line bg-surface p-8">
    <div class="flex flex-col gap-1">
        <p class="text-lg font-bold">{{ __('Verify it’s you') }}</p>
        <p class="text-sm text-fg-secondary">{{ __('We sent a 6-digit code to :email.', ['email' => $maskedEmail]) }}</p>
    </div>
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <x-ui.input :label="__('Code')" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
        <x-ui.button type="submit" size="lg">{{ __('Verify and sign in') }}</x-ui.button>
    </form>
</main>
</body>
</html>
