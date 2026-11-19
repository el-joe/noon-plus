@props(['label', 'name', 'value' => null, 'rows' => 3, 'scope' => null])

{{-- :scope (with a hidden _scope field in its form) keeps a refused form's errors and old input to that form only, when a page repeats the same field names in several forms (browser test BT-ADM-006). --}}
@php($fieldId = $attributes->get('id', $name))
@php($ours = $scope === null || old('_scope') === $scope)
@php($fieldError = $ours ? $errors->first($name) : null)

<div class="flex flex-col gap-1.5">
    <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="{{ $rows }}"
              @if ($fieldError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
              {{ $attributes->except('id')->class([
                  'rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-muted focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
                  'border-line-danger' => (bool) $fieldError,
                  'border-line' => ! (bool) $fieldError,
              ]) }}>{{ is_scalar($oldValue = ($ours ? old($name, $value) : $value)) ? $oldValue : "" }}</textarea>
    @if ($fieldError)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $fieldError }}</p>
    @endif
</div>
