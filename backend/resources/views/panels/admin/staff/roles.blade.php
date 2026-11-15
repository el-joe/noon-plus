<x-layouts.panel :title="__('Roles & permissions')" :subtitle="__('Admin roles are global; staff hold them per country. Default roles follow the permission matrix.')" :breadcrumbs="[__('Platform'), __('Roles & permissions')]">
    @include('panels.admin.staff._nav')

    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-xs" data-permission-matrix>
                <thead class="bg-subtle text-fg-secondary">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Permission') }}</th>
                    @foreach ($roles as $role)
                        <th class="px-3 py-2 text-center font-medium whitespace-nowrap">{{ str_replace('_', ' ', ucfirst($role->name)) }}@if (! in_array($role->name, $defaultRoles, true))<span class="block font-normal text-fg-brand">{{ __('custom') }}</span>@endif</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($groups as $module => $permissions)
                    <tr class="border-t border-line bg-page"><td colspan="{{ $roles->count() + 1 }}" class="px-4 py-1.5 font-semibold">{{ str_replace('_', ' ', ucfirst($module)) }}</td></tr>
                    @foreach ($permissions as $permission)
                        <tr class="border-t border-line">
                            <td class="px-4 py-1.5 font-mono">{{ $permission->value }}</td>
                            @foreach ($roles as $role)
                                <td class="px-3 py-1.5 text-center">{!! $role->permissions->contains('name', $permission->value) ? '<span class="text-fg-success" aria-label="'.e(__('yes')).'">●</span>' : '<span class="text-fg-muted" aria-label="'.e(__('no')).'">·</span>' !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @can('roles.manage')
        @foreach ($roles->reject(fn ($role) => in_array($role->name, $defaultRoles, true)) as $role)
            <details class="rounded-xl border border-line bg-surface p-5">
                <summary class="cursor-pointer font-semibold">{{ __('Edit custom role :name', ['name' => $role->name]) }}</summary>
                <form method="POST" action="{{ route('admin.roles.update', $role->id) }}" class="mt-4 flex flex-col gap-4">
                    @csrf @method('PUT')
                    @include('panels.admin.staff._permission-checkboxes', ['granted' => $role->permissions->pluck('name')->all(), 'prefix' => 'role-'.$role->id])
                    <div><x-ui.button type="submit">{{ __('Save role') }}</x-ui.button></div>
                </form>
            </details>
        @endforeach

        <x-ui.card :title="__('New custom role')">
            <form method="POST" action="{{ route('admin.roles.store') }}" class="flex flex-col gap-4">
                @csrf
                <div class="max-w-sm"><x-ui.input :label="__('Role name')" name="name" required :helper="__('Lower case, e.g. catalog_editor.')" /></div>
                @include('panels.admin.staff._permission-checkboxes', ['granted' => [], 'prefix' => 'new'])
                <div><x-ui.button type="submit">{{ __('Create role') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan
</x-layouts.panel>
