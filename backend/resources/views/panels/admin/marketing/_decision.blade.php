<div class="flex flex-col gap-2">
    <form method="POST" action="{{ $action }}">@csrf<input type="hidden" name="decision" value="approve"><x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button></form>
    <form method="POST" action="{{ $action }}" class="flex gap-2">@csrf<input type="hidden" name="decision" value="reject">
        <label class="sr-only" for="{{ $id }}">{{ __('Reject reason') }}</label>
        <input id="{{ $id }}" name="reason" required minlength="5" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
    </form>
</div>
