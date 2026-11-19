@props(['title', 'subtitle' => null, 'panelName' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Nawy {{ $panelName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-page px-4 font-sans text-fg antialiased">
<main class="flex w-full max-w-sm flex-col gap-6 rounded-2xl border border-line bg-surface p-8">
    <div class="flex items-center gap-2">
        <span class="grid size-10 place-items-center rounded-lg bg-inverse font-bold text-brand">N</span>
        <div>
            <p class="font-bold">{{ $title }}</p>
            @if ($subtitle)
                <p class="text-xs text-fg-secondary">{{ $subtitle }}</p>
            @endif
        </div>
    </div>
    @if (session('status'))
        <p class="rounded-lg bg-success-subtle px-3 py-2 text-sm text-fg" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->has('action'))
        <p class="rounded-lg bg-danger-subtle px-3 py-2 text-sm text-fg-danger" role="alert">{{ $errors->first('action') }}</p>
    @endif
    {{ $slot }}
    <x-ui.locale-switch />
</main>
</body>
</html>
