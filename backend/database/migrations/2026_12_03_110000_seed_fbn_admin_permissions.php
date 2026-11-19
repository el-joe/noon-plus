<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fulfilled by Nawy: `fbn.view` / `fbn.operate` / `fbn.configure`, granted
 * to the default operations role, and the new default `warehouse` role.
 * Nothing else is taken away or changed.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $fbn = ['fbn.view', 'fbn.operate', 'fbn.configure'];

    public function up(): void
    {
        foreach ($this->fbn as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull($teamKey)->first();

            if ($role === null && $roleName === 'warehouse') {
                foreach ($permissions as $permission) {
                    Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
                }

                $role = Role::query()->create(['name' => 'warehouse', 'guard_name' => 'web', $teamKey => null]);
                $role->givePermissionTo(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions));

                continue;
            }

            if ($role === null) {
                continue;
            }

            $granted = array_values(array_intersect(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions), $this->fbn));

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
