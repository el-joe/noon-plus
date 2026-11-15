<?php

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin → Platform → Roles & permissions (QC-004, matrix §3.2 `roles`):
 * every admin role with its permissions by module. The default roles come
 * from the matrix (AdminRoleSeeder) and are read-only here; custom roles
 * (SRS §16) are created and edited from any admin permission. Roles are
 * global; staff get them per country. Changes are audited.
 */
class RoleAdminController extends Controller
{
    public function index(): View
    {
        $roles = $this->adminRoles()->load('permissions');

        return view('panels.admin.staff.roles', [
            'roles' => $roles,
            'groups' => AdminPermission::grouped(),
            'defaultRoles' => array_keys(AdminPermission::defaultRoles()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web', config('permission.column_names.team_foreign_key') => null]);
        $role->syncPermissions($data['permissions'] ?? []);
        $this->audit($request, $role, [], $data['permissions'] ?? [], 'created');

        return back()->with('status', __('Role :name created.', ['name' => $role->name]));
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $role = $this->adminRoles()->firstWhere('id', $role) ?? abort(404);
        abort_if(in_array($role->name, array_keys(AdminPermission::defaultRoles()), true), 403, __('Default roles follow the permission matrix and cannot be edited here.'));
        $data = $this->validated($request, $role);
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions'] ?? []);
        $this->audit($request, $role, $before, $data['permissions'] ?? [], 'updated');

        return back()->with('status', __('Role :name saved.', ['name' => $role->name]));
    }

    /**
     * @return array{name: string, permissions?: array<int, string>}
     */
    private function validated(Request $request, ?Role $role): array
    {
        $request->merge(['name' => $role?->name ?? str((string) $request->input('name'))->lower()->snake()->toString()]);

        return $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->whereNull(config('permission.column_names.team_foreign_key'))->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_map(fn (AdminPermission $permission): string => $permission->value, AdminPermission::cases()))],
        ]);
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function audit(Request $request, Role $role, array $before, array $after, string $event): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        sort($after);

        activity('roles')->performedOn($role)->causedBy($request->user())->event($event)
            ->withProperties(['old' => ['permissions' => $before], 'attributes' => ['permissions' => array_values($after)]])
            ->log("Role {$role->name} {$event}");
    }

    /**
     * @return Collection<int, Role>
     */
    private function adminRoles(): Collection
    {
        return Role::query()->where('guard_name', 'web')->whereNull(config('permission.column_names.team_foreign_key'))
            ->where('name', 'not like', '%:%')->orderBy('name')->get();
    }
}
