<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * QA-029: `reviews.view`, `reviews.moderate` and `product_qa.moderate` were
 * only created by AdminRoleSeeder, so existing databases lacked them. Adds
 * every missing admin permission and grants it to the existing default
 * roles that hold it (nothing is taken away).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')
                ->whereNull(config('permission.column_names.team_foreign_key'))->first();

            if ($role === null) {
                continue;
            }

            $granted = array_map(fn (AdminPermission $permission): string => $permission->value, array_filter(
                $permissions,
                fn (AdminPermission $permission): bool => in_array($permission, [AdminPermission::ReviewsView, AdminPermission::ReviewsModerate, AdminPermission::ProductQaModerate], true),
            ));

            if ($granted !== []) {
                $role->givePermissionTo($granted);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
