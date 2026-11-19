@props(['label' => null, 'name', 'options' => [], 'selected' => null, 'placeholder' => null, 'useOld' => true, 'scope' => null])

@php($fieldId = $attributes->get('id', $name))
{{-- GET filter forms pass :use-old="false" so a row form's failed POST (same field name) does not change the filter. --}}
{{-- :scope (with a hidden _scope field in its form) keeps a refused form's errors and old input to that form only, when a page repeats the same field names in several forms (browser test BT-ADM-006). --}}
@php($ours = $scope === null || old('_scope') === $scope)
@php($fieldError = $ours ? $errors->first($name) : null)
@php($current = $useOld && $ours ? old($name, $selected) : $selected)
@php($current = is_scalar($current) ? (string) $current : '')

<div class="flex flex-col gap-1.5">
    @if ($label)
        <label for="{{ $fieldId }}" class="text-sm font-medium text-fg">{{ $label }}</label>
    @endif
    <select id="{{ $fieldId }}" name="{{ $name }}"
            @if ($fieldError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
            {{ $attributes->except('id')->class([
                'h-10 rounded-lg border bg-surface px-3 text-sm text-fg focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30',
                'border-line-danger' => (bool) $fieldError,
                'border-line' => ! (bool) $fieldError,
            ]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($fieldError)
        <p id="{{ $fieldId }}-error" class="text-xs text-fg-danger">{{ $fieldError }}</p>
    @endif
</div>
