<?php

namespace Database\Seeders;

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin permissions and the default admin roles (role-permission matrix §3.2).
 * Roles are global (no country); they are granted per country (team).
 */
class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                config('permission.column_names.team_foreign_key') => null,
            ]);
            $role->syncPermissions(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
