<x-layouts.auth-card :title="__('Choose a new password')" :subtitle="$email" :panel-name="$panelName">
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.input :label="__('Email')" name="email" type="email" autocomplete="username" :value="old('email', $email)" required />
        <x-ui.input :label="__('New password')" name="password" type="password" autocomplete="new-password" required autofocus />
        <x-ui.input :label="__('Confirm password')" name="password_confirmation" type="password" autocomplete="new-password" required />
        <x-ui.button type="submit" size="lg">{{ __('Reset password') }}</x-ui.button>
    </form>
</x-layouts.auth-card>
