<x-layouts.auth-card :title="__('Email preferences')" :subtitle="__('Unsubscribe')">
    @if ($data === null)
        <p class="text-sm text-fg-secondary">{{ __('This unsubscribe link is not valid. Transactional and security emails cannot be turned off.') }}</p>
    @else
        <div class="flex flex-col gap-2 text-sm">
            <p>{{ __('Topic') }}: <strong>{{ $data['label'] }}</strong></p>
            @if ($data['email'])<p class="text-fg-secondary">{{ $data['email'] }}</p>@endif
            <p class="text-fg-secondary">{{ $data['subscribed'] ? __('You currently receive these emails.') : __('You no longer receive these emails.') }}</p>
        </div>
        <form method="POST" action="{{ route('email.unsubscribe.submit', ['token' => $token]) }}">
            @csrf
            <input type="hidden" name="resubscribe" value="{{ $data['subscribed'] ? 0 : 1 }}">
            <x-ui.button type="submit" :variant="$data['subscribed'] ? 'primary' : 'outline'" class="w-full">{{ $data['subscribed'] ? __('Unsubscribe') : __('Subscribe again') }}</x-ui.button>
        </form>
    @endif
</x-layouts.auth-card>
