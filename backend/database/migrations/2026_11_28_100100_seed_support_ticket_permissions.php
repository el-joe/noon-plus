<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * QA wave 5 (QC-007): support ticket permissions `tickets.view` /
 * `tickets.manage` (matrix §3.2 Support "tickets"). Existing default roles
 * gain only these; nothing is taken away.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $wave5 = ['tickets.view', 'tickets.manage'];

    public function up(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull($teamKey)->first();

            if ($role === null) {
                continue;
            }

            $granted = array_values(array_intersect(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions), $this->wave5));

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
