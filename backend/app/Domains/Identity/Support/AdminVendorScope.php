<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * Account-manager scoping (QC-019, matrix §3.3): an admin holding
 * `admin.restricted_to_assigned_vendors` in the current country only sees
 * vendors whose `account_manager_id` is them, and every vendor-linked list
 * is narrowed to those vendors. `finance.hidden` masks platform economics.
 * Other admins are not restricted (null).
 */
class AdminVendorScope
{
    public function __construct(private AdminAccess $access) {}

    /**
     * @return array<int, int>|null null when the admin is not restricted
     */
    public function vendorIds(): ?array
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return $this->access->allows($user, AdminPermission::RestrictedToAssignedVendors)
            ? Vendor::query()->where('account_manager_id', $user->id)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : null;
    }

    public function isRestricted(): bool
    {
        return $this->vendorIds() !== null;
    }

    public function financeHidden(): bool
    {
        $user = Auth::user();

        return $user !== null && $this->access->allows($user, AdminPermission::FinanceHidden);
    }

    /**
     * Narrow a query on a table with a vendor column to the assigned vendors.
     *
     * @template TBuilder of Builder|Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder|Relation $query, string $column = 'vendor_id'): Builder|Relation
    {
        $ids = $this->vendorIds();

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /**
     * Narrow a query through a relation that has a vendor column.
     *
     * @template TBuilder of Builder|Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyThrough(Builder|Relation $query, string $relation, string $column = 'vendor_id'): Builder|Relation
    {
        $ids = $this->vendorIds();

        return $ids === null ? $query : $query->whereHas($relation, fn (Builder $related) => $related->whereIn($column, $ids));
    }

    public function allowsVendor(?int $vendorId): bool
    {
        $ids = $this->vendorIds();

        return $ids === null || ($vendorId !== null && in_array($vendorId, $ids, true));
    }
}
