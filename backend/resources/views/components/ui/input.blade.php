@props(['label', 'name', 'type' => 'text', 'value' => null, 'helper' => null])

@php($fieldId = $attributes->get('id', $name))

<div class="flex flex-col gap-1.5">
    <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
        {{ $attributes->except('id')->class([
            'h-10 rounded-lg border bg-surface px-3 text-sm text-fg placeholder:text-fg-muted focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
            'border-line-danger' => $errors->has($name),
            'border-line' => ! $errors->has($name),
        ]) }}
    >
    @error($name)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $message }}</p>
    @elseif ($helper)
        <p class="text-xs text-fg-secondary">{{ $helper }}</p>
    @enderror
</div>
