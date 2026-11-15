<x-layouts.auth-card :title="__('Set your password')" :subtitle="$email" :panel-name="$panelName">
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <x-ui.input :label="__('New password')" name="password" type="password" autocomplete="new-password" required autofocus />
        <x-ui.input :label="__('Confirm password')" name="password_confirmation" type="password" autocomplete="new-password" required />
        <x-ui.button type="submit" size="lg">{{ __('Save password') }}</x-ui.button>
    </form>
</x-layouts.auth-card>
