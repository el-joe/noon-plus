<?php

namespace App\Domains\Identity\Support;

use Illuminate\Support\Facades\DB;

/**
 * Display names of admin roles: a custom role shows the name as typed
 * (`roles.display_name`, browser test BT-ADM-010); a default role its key
 * in words ("catalog_manager" → "Catalog manager").
 */
final class AdminRoleLabels
{
    /** @var array<string, string|null>|null */
    private ?array $typed = null;

    public function label(?string $roleName): string
    {
        if ($roleName === null || $roleName === '') {
            return '';
        }

        $this->typed ??= DB::table('roles')->whereNotNull('display_name')->pluck('display_name', 'name')->all();

        return $this->typed[$roleName] ?? str_replace('_', ' ', ucfirst($roleName));
    }
}
