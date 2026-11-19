<x-layouts.auth-card :title="__('Forgot your password?')" :subtitle="__('Enter your work email and we will send you a reset link.')" :panel-name="$panelName">
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <x-ui.input :label="__('Email')" name="email" type="email" autocomplete="username" :value="old('email')" required autofocus />
        <x-ui.button type="submit" size="lg">{{ __('Send reset link') }}</x-ui.button>
    </form>
    <a href="{{ $loginUrl }}" class="text-center text-sm text-fg-secondary underline">{{ __('Back to sign in') }}</a>
</x-layouts.auth-card>
