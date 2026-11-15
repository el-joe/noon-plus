<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($groups as $module => $permissions)
        <fieldset class="flex flex-col gap-1">
            <legend class="mb-1 text-sm font-medium">{{ str_replace('_', ' ', ucfirst($module)) }}</legend>
            @foreach ($permissions as $permission)
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" id="{{ $prefix }}-{{ $permission->value }}" name="permissions[]" value="{{ $permission->value }}" class="size-4 accent-brand" @checked(in_array($permission->value, $granted, true))> {{ $permission->value }}</label>
            @endforeach
        </fieldset>
    @endforeach
</div>
