<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Campaign invitation') }} · Nawy Creator Studio</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-page px-4 font-sans text-fg">
<main class="flex w-full max-w-md flex-col gap-4 rounded-2xl border border-line bg-surface p-6" data-invitation-response>
    <div class="flex items-center gap-2">
        <span class="grid size-9 place-items-center rounded-lg bg-brand font-bold text-fg-on-brand">N</span>
        <span class="font-bold">Nawy · {{ __('Creator Studio') }}</span>
    </div>
    <h1 class="text-xl font-bold">{{ $invitation->campaign->title }}</h1>
    <p class="text-sm text-fg-secondary">{{ $invitation->campaign->listing->variant->product->title_en }}</p>
    <p class="text-sm">
        @if ($invitation->influencer_fixed)
            {{ __('Fixed fee: :amount', ['amount' => app(\App\Shared\Money\MoneyFormatter::class)->format($invitation->influencer_fixed, $invitation->campaign->currency)]) }}
        @else
            {{ __('Commission per sale: :rate%', ['rate' => $invitation->rate_snapshot['affiliate_percent'] ?? $invitation->rate_snapshot['broker_percent'] ?? 0]) }}
        @endif
    </p>
    @if ($done)
        <div role="status" class="rounded-lg bg-success-subtle px-4 py-3 text-sm text-fg-success">{{ $done }}</div>
    @elseif ($invitation->status->value !== 'pending')
        <div role="status" class="rounded-lg bg-subtle px-4 py-3 text-sm">{{ __('This invitation is :status.', ['status' => str_replace('_', ' ', $invitation->status->value)]) }}</div>
    @else
        <p class="text-xs text-fg-secondary">{{ __('Answer by :time', ['time' => $invitation->expires_at?->format('j M H:i')]) }}</p>
        <form method="POST" action="{{ $action }}" class="flex flex-col gap-3">
            @csrf
            @if ($answer === 'decline')
                <x-ui.input :label="__('Reason (optional)')" name="reason" />
                <x-ui.button type="submit" variant="outline">{{ __('Decline invitation') }}</x-ui.button>
            @elseif ($answer === 'accept')
                <x-ui.button type="submit">{{ __('Accept invitation') }}</x-ui.button>
            @else
                <x-ui.button type="submit" name="answer" value="accept">{{ __('Accept invitation') }}</x-ui.button>
                <x-ui.button type="submit" name="answer" value="decline" variant="outline">{{ __('Decline') }}</x-ui.button>
            @endif
        </form>
    @endif
</main>
</body>
</html>
