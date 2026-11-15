<?php

use App\Domains\Identity\Enums\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * QA wave 2 (QC-003, QC-004, QC-019): the admin back-office permissions
 * (catalog, coupons, gift cards, Nawy One, payment review, vendors, staff,
 * roles, activity log, system health) and the account-manager role. Existing
 * default roles gain only the new permissions they hold by default; nothing
 * is taken away.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $wave2 = [
        'categories.view', 'categories.manage', 'brands.view', 'brands.manage', 'products.view', 'products.update',
        'coupons.view', 'coupons.manage', 'gift_cards.view', 'gift_cards.manage', 'membership.view', 'membership.manage',
        'payments.review', 'vendors.view', 'vendors.update', 'vendors.assign_account_manager',
        'admins.view', 'admins.manage', 'roles.view', 'roles.manage', 'activity_log.view',
        'system_health.view', 'system_health.manage', 'admin.restricted_to_assigned_vendors', 'finance.hidden',
    ];

    public function up(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (AdminPermission::defaultRoles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->whereNull($teamKey)->first();

            if ($role === null && $roleName !== 'account_manager') {
                continue;
            }

            $role ??= Role::query()->create(['name' => $roleName, 'guard_name' => 'web', $teamKey => null]);
            $granted = array_values(array_intersect(array_map(fn (AdminPermission $permission): string => $permission->value, $permissions), $this->wave2));

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
