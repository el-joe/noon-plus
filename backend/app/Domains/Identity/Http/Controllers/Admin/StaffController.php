<?php

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\RoleMembership;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Panels\Models\TeamInvitation;
use App\Domains\Panels\Support\TeamInvitations;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin → Platform → Staff (QC-004, matrix §3.2 `admins`): the admins of
 * the current country (and the super admins), inviting staff by email with
 * a role in this country (team invitation — the address is proven before an
 * account exists), changing their roles here, suspending and reactivating
 * their admin membership here. Super admins are created with
 * `nawy:admin:create` and only a super admin can suspend one. Membership
 * and role changes are audited (activitylog).
 */
class StaffController extends Controller
{
    public function __construct(private CountryContext $countryContext, private AdminAccess $access) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'role' => ['nullable', 'string', 'max:64'],
        ]);
        $country = $this->countryContext->current();

        $staff = User::query()
            ->whereHas('memberships', fn (Builder $membership) => $this->adminMembershipsHere($membership)
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status)))
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner->where('name', 'like', LikeSearch::contains($search))->orWhere('email', 'like', LikeSearch::contains($search))))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $role)))
            ->with(['memberships' => fn ($membership) => $this->adminMembershipsHere($membership), 'roles'])
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('panels.admin.staff.index', [
            'staff' => $staff,
            'filters' => $filters,
            'country' => $country,
            'roles' => $this->assignableRoles(),
            'invitations' => TeamInvitation::query()->where('tenant_type', TeamInvitations::PLATFORM_TENANT)->where('role', MembershipRole::Admin)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->get(),
        ]);
    }

    public function show(int $user): View
    {
        $staff = $this->findStaff($user);

        return view('panels.admin.staff.show', [
            'member' => $staff,
            'country' => $this->countryContext->current(),
            'memberships' => $staff->memberships()->where('role', MembershipRole::Admin)->with('country')->get(),
            'rolesHere' => $staff->roles->pluck('name')->all(),
            'roles' => $this->assignableRoles(),
            'isSuper' => $this->access->isSuperAdmin($staff),
            'activity' => Activity::query()->where('causer_type', $staff->getMorphClass())->where('causer_id', $staff->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function invite(Request $request, TeamInvitations $invitations): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in($this->assignableRoles()->pluck('name')->all())],
        ]);
        $invitations->invite('admin', TeamInvitations::PLATFORM_TENANT, 0, 'Nawy', MembershipRole::Admin, $data['role'], $data['email'], $data['name'], [], $request->user()->id);

        activity('staff')->causedBy($request->user())->event('invited')
            ->withProperties(['country' => $this->countryContext->current()->code, 'email' => mb_strtolower($data['email']), 'role' => $data['role']])
            ->log('Admin staff invited');

        return back()->with('status', __('Invitation sent to :email.', ['email' => $data['email']]));
    }

    public function revokeInvitation(Request $request, int $invitation, TeamInvitations $invitations): RedirectResponse
    {
        $invitations->revoke(TeamInvitation::query()->where('tenant_type', TeamInvitations::PLATFORM_TENANT)->findOrFail($invitation));

        return back()->with('status', __('Invitation revoked.'));
    }

    public function roles(Request $request, int $user): RedirectResponse
    {
        $staff = $this->findStaff($user);
        abort_if($staff->is($request->user()), 403, __('You cannot change your own roles.'));
        $data = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles()->pluck('name')->all())],
        ]);
        $before = $staff->roles->pluck('name')->sort()->values()->all();
        $staff->syncRoles($data['roles'] ?? []);
        $staff->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('staff')->performedOn($staff)->causedBy($request->user())->event('roles_changed')
            ->withProperties(['country' => $this->countryContext->current()->code, 'old' => $before, 'attributes' => collect($data['roles'] ?? [])->sort()->values()->all()])
            ->log('Admin roles changed');

        return back()->with('status', __('Roles saved for :country.', ['country' => $this->countryContext->current()->name_en]));
    }

    public function status(Request $request, int $user): RedirectResponse
    {
        $staff = $this->findStaff($user);
        abort_if($staff->is($request->user()), 403, __('You cannot suspend yourself.'));
        $status = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]])['status'];
        $isSuper = $this->access->isSuperAdmin($staff) || $staff->memberships()->where('role', MembershipRole::Admin)->whereNull('country_id')->exists();
        abort_if($isSuper && ! $this->access->isSuperAdmin($request->user()), 403, __('Only a super admin can suspend a super admin.'));

        $staff->memberships()->where('role', MembershipRole::Admin)
            ->where(fn ($query) => $isSuper ? $query->whereNull('country_id') : $query->where('country_id', $this->countryContext->current()->id))
            ->get()->each(fn (RoleMembership $membership) => $membership->update(['status' => $status]));

        return back()->with('status', $status === 'suspended' ? __('Admin access suspended.') : __('Admin access restored.'));
    }

    /**
     * Admin memberships that reach the current country (its own and super).
     *
     * @param  Builder<RoleMembership>|Relation<RoleMembership, User, mixed>  $query
     */
    private function adminMembershipsHere($query)
    {
        return $query->where('role', MembershipRole::Admin)
            ->where(fn ($inner) => $inner->where('country_id', $this->countryContext->current()->id)->orWhereNull('country_id'));
    }

    private function findStaff(int $user): User
    {
        return User::query()->whereHas('memberships', fn (Builder $membership) => $this->adminMembershipsHere($membership))->with('roles')->findOrFail($user);
    }

    /**
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        return Role::query()->where('guard_name', 'web')->whereNull(config('permission.column_names.team_foreign_key'))->where('name', 'not like', '%:%')->orderBy('name')->get();
    }
}
