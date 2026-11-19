@props(['label', 'name', 'type' => 'text', 'value' => null, 'helper' => null, 'scope' => null])

{{-- :scope (with a hidden _scope field in its form) keeps a refused form's errors and old input to that form only, when a page repeats the same field names in several forms (browser test BT-ADM-006). --}}
@php($fieldId = $attributes->get('id', $name))
@php($ours = $scope === null || old('_scope') === $scope)
@php($fieldError = $ours ? $errors->first($name) : null)
@php($current = $ours ? old($name, $value) : $value)
{{-- Money is stored with 4 decimals ("25.0000"); show it without the storage zeros so an unchanged
     form saves again under the currency's decimal rule (browser test BT-ADM-003). --}}
@php($current = is_string($current) && preg_match('/^-?\d+\.\d{4}$/', $current) ? rtrim(rtrim($current, '0'), '.') : $current)

<div class="flex flex-col gap-1.5">
    <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ is_scalar($current) ? $current : '' }}"
        @if ($fieldError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
        {{ $attributes->except('id')->class([
            'h-10 rounded-lg border bg-surface px-3 text-sm text-fg placeholder:text-fg-muted focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
            'border-line-danger' => (bool) $fieldError,
            'border-line' => ! (bool) $fieldError,
        ]) }}
    >
    @if ($fieldError)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $fieldError }}</p>
    @elseif ($helper)
        <p class="text-xs text-fg-secondary">{{ $helper }}</p>
    @endif
</div>
