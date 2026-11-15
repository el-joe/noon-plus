@props(['label' => null, 'name', 'options' => [], 'selected' => null, 'placeholder' => null])

@php($fieldId = $attributes->get('id', $name))
@php($current = old($name, $selected))
@php($current = is_scalar($current) ? (string) $current : '')

<div class="flex flex-col gap-1.5">
    @if ($label)
        <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    @endif
    <select id="{{ $fieldId }}" name="{{ $name }}"
            @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror
            {{ $attributes->except('id')->class([
                'h-10 rounded-lg border bg-surface px-3 text-sm text-fg focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
                'border-line-danger' => $errors->has($name),
                'border-line' => ! $errors->has($name),
            ]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @error($name)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $message }}</p>
    @enderror
</div>
