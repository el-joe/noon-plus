{{-- D16 / O1: live dispatch notice; resources/js/live-dispatch.js joins `country.{code}.ops` when Reverb is configured. --}}
<div class="flex flex-wrap items-center gap-2 text-xs text-fg-secondary"
     data-live-dispatch="{{ $countryCode }}"
     data-live-connected="{{ __('Live: dispatch changes appear here as they happen.') }}"
     data-live-offline="{{ __('Live updates are not available right now; this board refreshes when you reload.') }}"
     data-live-changes="{{ __(':count dispatch changes since this page loaded.') }}">
    <span data-live-status>{{ __('This board refreshes when you reload.') }}</span>
    <span class="hidden" data-live-reload><x-ui.button size="sm" variant="outline">{{ __('Reload') }}</x-ui.button></span>
</div>
