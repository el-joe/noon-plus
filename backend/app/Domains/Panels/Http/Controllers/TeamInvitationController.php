<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Panels\Models\TeamInvitation;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\TeamInvitations;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The emailed invitation link (QA-003, QC-020): shows who invites and for
 * which role, and lets the invitee accept — choosing a password when the
 * email has no account yet. Nothing is attached before this answer.
 */
class TeamInvitationController extends Controller
{
    public function __construct(private TeamInvitations $invitations, private CountryContext $countryContext) {}

    public function show(Request $request, string $country, string $token): View
    {
        $invitation = $this->invitation($country, $token);

        return view('panels.auth.team-invitation', [
            'panelName' => Panel::find($request->route('panel'))->name,
            'invitation' => $invitation,
            'teamName' => $this->teamName($invitation),
            'roleLabel' => $this->invitations->roleLabel($invitation->role, $invitation->tenant_role),
            'needsPassword' => ! $this->invitations->accountExists($invitation),
            'action' => $request->url(),
        ]);
    }

    public function accept(Request $request, string $country, string $token): RedirectResponse
    {
        $invitation = $this->invitation($country, $token);
        $needsPassword = ! $this->invitations->accountExists($invitation);
        $data = $request->validate($needsPassword ? ['password' => ['required', 'string', 'confirmed', Password::min(8)]] : []);

        try {
            $this->invitations->accept($invitation, $data['password'] ?? null);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        $panel = Panel::find($request->route('panel'));

        return redirect()->route($panel->route('login'))->with('status', __('Invitation accepted. Sign in to continue.'));
    }

    private function invitation(string $country, string $token): TeamInvitation
    {
        $this->countryContext->set(Country::query()->where('code', $country)->firstOrFail());

        return $this->invitations->findPending($token) ?? abort(404, __('This invitation is no longer valid.'));
    }

    private function teamName(TeamInvitation $invitation): string
    {
        return match ($invitation->tenant_type) {
            Carrier::TENANT => (string) Carrier::query()->whereKey($invitation->tenant_id)->value('name'),
            TeamInvitations::PLATFORM_TENANT => 'Nawy',
            default => (string) Vendor::query()->whereKey($invitation->tenant_id)->value('store_name'),
        };
    }
}
