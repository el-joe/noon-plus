<x-layouts.auth-card :title="__('Join :team', ['team' => $teamName])" :subtitle="__('Invitation for :email as :role', ['email' => $invitation->email, 'role' => $roleLabel])" :panel-name="$panelName">
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        @if ($needsPassword)
            <p class="text-sm text-fg-secondary">{{ __('Choose a password for your new Nawy account.') }}</p>
            <x-ui.input :label="__('Password')" name="password" type="password" autocomplete="new-password" required autofocus />
            <x-ui.input :label="__('Confirm password')" name="password_confirmation" type="password" autocomplete="new-password" required />
        @else
            <p class="text-sm text-fg-secondary">{{ __('This email already has a Nawy account. Accepting adds this team to it; your password does not change.') }}</p>
        @endif
        <x-ui.button type="submit" size="lg">{{ __('Accept invitation') }}</x-ui.button>
    </form>
</x-layouts.auth-card>
