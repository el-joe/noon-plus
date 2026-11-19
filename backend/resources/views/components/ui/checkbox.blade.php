@props(['label', 'name', 'checked' => false, 'scope' => null])

@php($fieldId = $attributes->get('id', $name))

<label for="{{ $fieldId }}" class="flex items-center gap-2 text-sm text-fg">
    <input type="hidden" name="{{ $name }}" value="0">
    <input id="{{ $fieldId }}" name="{{ $name }}" type="checkbox" value="1" @checked($scope === null || old('_scope') === $scope ? old($name, $checked) : $checked)
           {{ $attributes->except('id')->class('size-4 rounded border-line accent-brand') }}>
    {{ $label }}
</label>
