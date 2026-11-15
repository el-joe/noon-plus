<?php

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The WhatsApp invitation page (Figma 101:16484, ADR 5): a signed,
 * expiring link lets the marketer accept or decline without signing in.
 * The signature covers the country, invitation and answer.
 */
class InvitationResponseController extends Controller
{
    public function show(Request $request, string $country, int $invitation, CountryContext $countryContext): View
    {
        $invitation = $this->invitation($country, $invitation, $countryContext);

        return view('panels.marketer.invitation-response', [
            'invitation' => $invitation,
            'answer' => $request->query('answer'),
            'action' => $request->fullUrl(),
            'done' => null,
        ]);
    }

    public function store(Request $request, string $country, int $invitation, CountryContext $countryContext, Invitations $invitations): View
    {
        $invitation = $this->invitation($country, $invitation, $countryContext);
        $answer = $request->query('answer') ?? $request->input('answer');
        $error = null;

        try {
            if ($invitation->status === InvitationStatus::Pending) {
                $answer === 'accept'
                    ? $invitations->accept($invitation, $invitation->marketer->user_id, $request->input('note'))
                    : $invitations->reject($invitation, $invitation->marketer->user_id, $request->input('reason') ?: 'Declined on WhatsApp');
            }
        } catch (TransitionRefused $refused) {
            $error = $refused->getMessage();
        }

        return view('panels.marketer.invitation-response', [
            'invitation' => $invitation->refresh(),
            'answer' => $answer,
            'action' => $request->fullUrl(),
            'done' => $error ?? ($invitation->status === InvitationStatus::Accepted ? __('Accepted — your referral link is in Creator Studio.') : __('Thanks, your answer was recorded.')),
        ]);
    }

    private function invitation(string $code, int $id, CountryContext $countryContext): CampaignInvitation
    {
        $countryContext->set(Country::query()->where('code', $code)->firstOrFail());

        return CampaignInvitation::query()->with('campaign.listing.variant.product', 'marketer')->findOrFail($id);
    }
}
