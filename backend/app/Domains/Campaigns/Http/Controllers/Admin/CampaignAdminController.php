<?php

namespace App\Domains\Campaigns\Http\Controllers\Admin;

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\CampaignSample;
use App\Domains\Campaigns\Models\ContentSubmission;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignRefused;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\ContentSubmissions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Campaigns\Support\Samples;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Marketer campaigns (matrix §3.2 `marketer_campaigns`): the
 * review queue (approve / reject with reason), platform campaigns
 * (origin C), invite more, WhatsApp resend, samples (ship, deliver,
 * returned) and content review.
 */
class CampaignAdminController extends Controller
{
    public function __construct(private CampaignStateMachine $campaigns) {}

    public function index(Request $request): View
    {
        return view('panels.admin.marketers.campaigns', [
            'campaigns' => MarketerCampaign::query()->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
                ->with('listing.variant.product', 'vendor')->latest('id')->paginate(25)->withQueryString(),
            'statuses' => CampaignStatus::cases(),
            'listings' => Listing::query()->where('status', ListingStatus::Active)->with('variant.product')->limit(200)->get(),
            'influencers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)->whereJsonContains('job_types', 'influencer')->orderBy('display_name')->get(),
        ]);
    }

    public function store(Request $request, CampaignWizard $wizard): RedirectResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'influencer_ids' => ['array'],
            'influencer_ids.*' => ['integer'],
            'open_to_affiliates' => ['boolean'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'ends_at' => ['nullable', 'date'],
        ]);

        try {
            $campaign = $wizard->create(CampaignOrigin::Platform, $data + ['budget' => isset($data['budget']) ? (string) $data['budget'] : null], null, $request->user()->id);
        } catch (CampaignRefused $refused) {
            return back()->withInput()->withErrors([$refused->field => $refused->getMessage()]);
        }

        return redirect()->route('admin.marketers.campaigns.show', $campaign->id)->with('status', __('Platform campaign created.'));
    }

    public function show(int $campaign): View
    {
        $campaign = MarketerCampaign::query()->with('listing.variant.product', 'vendor', 'invitations.marketer', 'samples', 'contentSubmissions', 'transitions', 'requester')->findOrFail($campaign);

        return view('panels.admin.marketers.campaign', [
            'campaign' => $campaign,
            'remaining' => $campaign->budget === null ? null : $this->campaigns->remainingBudget($campaign),
            'influencers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)->whereJsonContains('job_types', 'influencer')->orderBy('display_name')->get(),
        ]);
    }

    public function transition(Request $request, int $campaign): RedirectResponse
    {
        $campaign = MarketerCampaign::query()->findOrFail($campaign);
        $userId = $request->user()->id;

        match ($request->input('action')) {
            'approve' => $this->campaigns->approve($campaign, $userId),
            'reject' => $this->campaigns->reject($campaign, ActorType::Admin, $userId, (string) $request->input('reason')),
            'pause' => $this->campaigns->pause($campaign, ActorType::Admin, $userId, $request->input('reason')),
            'resume' => $this->campaigns->resume($campaign, ActorType::Admin, $userId),
            'complete' => $this->campaigns->complete($campaign, ActorType::Admin, $userId, 'Completed by admin'),
            default => $this->campaigns->cancel($campaign, ActorType::Admin, $userId, $request->input('reason')),
        };

        return back()->with('status', __('Campaign :status.', ['status' => str_replace('_', ' ', $campaign->refresh()->status->value)]));
    }

    public function invite(Request $request, int $campaign, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate(['influencer_ids' => ['required', 'array'], 'influencer_ids.*' => ['integer']]);
        $skipped = $invitations->inviteMore(MarketerCampaign::query()->findOrFail($campaign), $data['influencer_ids']);

        return back()->with('status', $skipped === [] ? __('Invitations sent.') : __('Some marketers were skipped: :reasons', ['reasons' => implode('; ', array_unique($skipped))]));
    }

    public function resend(int $invitation, Invitations $invitations): RedirectResponse
    {
        $invitations->resend(CampaignInvitation::query()->findOrFail($invitation));

        return back()->with('status', __('WhatsApp invitation resent.'));
    }

    public function sample(Request $request, int $sample, Samples $samples): RedirectResponse
    {
        $sample = CampaignSample::query()->findOrFail($sample);
        $userId = $request->user()->id;

        match ($request->input('action')) {
            'ship' => $samples->ship($sample, ActorType::Admin, $userId, $request->input('tracking_number')),
            'deliver' => $samples->deliver($sample, ActorType::Admin, $userId),
            default => $samples->markReturned($sample, ActorType::Admin, $userId),
        };

        return back()->with('status', __('Sample updated.'));
    }

    public function content(Request $request, int $submission, ContentSubmissions $content): RedirectResponse
    {
        $submission = ContentSubmission::query()->findOrFail($submission);

        $request->input('decision') === 'approve'
            ? $content->approve($submission, ActorType::Admin, $request->user()->id)
            : $content->reject($submission, ActorType::Admin, $request->user()->id, (string) $request->input('reason'));

        return back()->with('status', __('Content reviewed.'));
    }
}
