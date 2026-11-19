<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * QC-016: product requests, profile change requests and packaging orders.
 * The new permissions are granted to the default roles that hold them
 * (operations, finance, vendor relations); nothing else changes.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'product_requests.view', 'product_requests.review', 'change_requests.view', 'change_requests.review', 'packaging.view', 'packaging.manage',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull($teamKey)->first();
            $granted = array_values(array_intersect(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions), $this->permissions));

            if ($role !== null && $granted !== []) {
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
