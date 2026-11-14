@php($fieldId = $attributes->get('id', $name))

<div class="flex flex-col gap-1.5" data-country-datetime data-timezone="{{ $country->timezone }}" data-admin-timezone="{{ config('nawy.admin_timezone') }}">
    <label for="{{ $fieldId }}" class="flex items-center gap-2 text-sm font-medium text-fg">
        {{ $label }}
        <x-ui.badge tone="info">{{ $country->name_en }} · {{ $country->timezone }}</x-ui.badge>
    </label>
    <input id="{{ $fieldId }}" name="{{ $name }}" type="datetime-local" value="{{ old($name, $localValue) }}"
           {{ $attributes->except('id')->class('h-10 rounded-lg border border-line bg-surface px-3 text-sm text-fg focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30') }}>
    <p class="text-xs text-fg-secondary" data-admin-time-label>{{ $adminTimeLabel ?? __('Pick a time to see the Oman equivalent') }}</p>
</div>
