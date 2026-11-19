<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FR-FIN-6 / FR-FIN-7: acquisition commission and vendor subscription
 * permissions. Existing default roles gain only these new permissions they
 * hold by default (finance: all four; vendor relations: subscriptions view).
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $added = [
        'acquisition_commissions.view', 'acquisition_commissions.manage', 'vendor_subscriptions.view', 'vendor_subscriptions.manage',
    ];

    public function up(): void
    {
        foreach ($this->added as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull($teamKey)->first();
            $granted = array_values(array_intersect(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions), $this->added));

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
