<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\RoleMembership;
use App\Domains\Panels\Models\TeamInvitation;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Panels\Support\TeamInvitations;
use App\Domains\Panels\Support\TenantPermissions;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Team & permissions (QC-020, role-permission matrix §5.1 / §6): the store
 * or travel agency owner invites staff by email with a staff role, changes
 * a member's role or removes them. Owner only (`tenant.can:…,team`).
 */
class TeamController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $panel = $request->route('panel');
        $tenant = $this->tenant($request);

        return view('panels.shared.team', [
            'panel' => $panel,
            'tenant' => $tenant,
            'members' => $this->members($request, $tenant)->with('user:id,name,email')->get(),
            'invitations' => TeamInvitation::query()->where('tenant_type', PanelCountries::VENDOR_TENANT)->where('tenant_id', $tenant->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->get(),
            'roles' => collect(TenantPermissions::roles($panel))->mapWithKeys(fn (string $role): array => [$role => TenantPermissions::roleLabel($role)])->all(),
        ]);
    }

    public function invite(Request $request, TeamInvitations $invitations): RedirectResponse
    {
        $panel = $request->route('panel');
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(TenantPermissions::roles($panel))],
        ]);
        $invitations->invite($panel, PanelCountries::VENDOR_TENANT, $tenant->id, $tenant->store_name, $this->membershipRole($panel, $tenant), $data['role'], $data['email'], $data['name'], [], $request->user()->id);

        return back()->with('status', __('We emailed an invitation to :email.', ['email' => mb_strtolower($data['email'])]));
    }

    public function updateRole(Request $request, int $membership): RedirectResponse
    {
        $panel = $request->route('panel');
        $role = $request->validate(['role' => ['required', Rule::in(TenantPermissions::roles($panel))]])['role'];
        $this->member($request, $membership)->update(['tenant_role' => $role]);

        return back()->with('status', __('Role updated.'));
    }

    public function destroy(Request $request, int $membership): RedirectResponse
    {
        $this->member($request, $membership)->update(['status' => 'suspended']);

        return back()->with('status', __('The member was removed from the team.'));
    }

    public function revoke(Request $request, int $invitation, TeamInvitations $invitations): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $invitations->revoke(TeamInvitation::query()->where('tenant_type', PanelCountries::VENDOR_TENANT)->where('tenant_id', $tenant->id)->findOrFail($invitation));

        return back()->with('status', __('Invitation withdrawn.'));
    }

    private function tenant(Request $request): Vendor
    {
        return $request->route('panel') === 'travel_agency' ? $request->attributes->get('agency') : app(VendorWorkspace::class)->vendor();
    }

    private function membershipRole(string $panel, Vendor $tenant): MembershipRole
    {
        return match (true) {
            $panel === 'travel_agency' => MembershipRole::TravelAgency,
            $tenant->type === 'classified' => MembershipRole::ClassifiedSeller,
            default => MembershipRole::ProductVendor,
        };
    }

    /**
     * @return Builder<RoleMembership>
     */
    private function members(Request $request, Vendor $tenant)
    {
        return RoleMembership::query()->where('tenant_type', PanelCountries::VENDOR_TENANT)->where('tenant_id', $tenant->id)
            ->where('country_id', $this->countryContext->current()->id)->where('status', 'active')
            ->whereIn('role', $request->route('panel') === 'travel_agency' ? [MembershipRole::TravelAgency] : [MembershipRole::ProductVendor, MembershipRole::ClassifiedSeller]);
    }

    /**
     * A staff member of this tenant (never the owner).
     */
    private function member(Request $request, int $id): RoleMembership
    {
        $tenant = $this->tenant($request);
        $membership = $this->members($request, $tenant)->findOrFail($id);
        abort_if($membership->user_id === $tenant->owner_user_id, 403, __('The owner keeps every right.'));

        return $membership;
    }
}
