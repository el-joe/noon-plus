@props(['label', 'name', 'value' => null, 'rows' => 3])

@php($fieldId = $attributes->get('id', $name))

<div class="flex flex-col gap-1.5">
    <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="{{ $rows }}"
              @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
              {{ $attributes->except('id')->class([
                  'rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-muted focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
                  'border-line-danger' => $errors->has($name),
                  'border-line' => ! $errors->has($name),
              ]) }}>{{ is_scalar($oldValue = old($name, $value)) ? $oldValue : "" }}</textarea>
    @error($name)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $message }}</p>
    @enderror
</div>
