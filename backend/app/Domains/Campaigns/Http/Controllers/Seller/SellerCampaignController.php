<?php

namespace App\Domains\Campaigns\Http\Controllers\Seller;

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\CampaignSample;
use App\Domains\Campaigns\Models\ContentSubmission;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignRefused;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\ContentSubmissions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Campaigns\Support\Samples;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Marketer campaigns (SRS §11.4, matrix §4 `campaigns`,
 * Figma 100:8967): the wizard with the live fee quote, the store's
 * campaigns (incl. marketer requests and peer promotions of its listings),
 * cancel / pause / resume, invite more, approve or reject a marketer
 * request, ship samples and review content. Every query is store-scoped.
 */
class SellerCampaignController extends Controller
{
    public function __construct(private VendorWorkspace $workspace, private CampaignStateMachine $campaigns) {}

    public function index(Request $request): View
    {
        $campaigns = MarketerCampaign::query()->where('vendor_id', $this->workspace->vendor()->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->with('listing.variant.product')->latest('id')->paginate(20)->withQueryString();

        return view('panels.partner.campaigns.index', ['campaigns' => $campaigns, 'statuses' => CampaignStatus::cases()]);
    }

    public function create(ContractGates $gates): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.campaigns.create', [
            'listings' => Listing::query()->where('vendor_id', $vendor->id)->where('status', ListingStatus::Active)->with('variant.product')->get(),
            'influencers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)->whereJsonContains('job_types', 'influencer')->orderByDesc('followers')->get(),
            'missingContracts' => $gates->missingCollaboration($vendor, ['influencer', 'affiliate', 'broker']),
        ]);
    }

    public function quote(Request $request, CampaignWizard $wizard): JsonResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'influencer_ids' => ['array'],
            'influencer_ids.*' => ['integer'],
        ]);
        $listing = Listing::query()->where('vendor_id', $this->workspace->vendor()->id)->with('variant.product.category')->findOrFail($data['listing_id']);

        return response()->json(['data' => $wizard->quoteFor($listing, isset($data['budget']) ? (string) $data['budget'] : null, $data['influencer_ids'] ?? [])]);
    }

    public function store(Request $request, CampaignWizard $wizard): RedirectResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'influencer_ids' => ['array'],
            'influencer_ids.*' => ['integer'],
            'open_to_affiliates' => ['boolean'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'ends_at' => ['nullable', 'date'],
            'ad_types' => ['array'],
            'ad_types.*' => ['string', 'max:32'],
        ]);

        try {
            $campaign = $wizard->create(CampaignOrigin::Vendor, $data + ['budget' => isset($data['budget']) ? (string) $data['budget'] : null], $this->workspace->vendor(), $request->user()->id);
        } catch (CampaignRefused $refused) {
            return back()->withInput()->withErrors([$refused->field => $refused->getMessage()]);
        }

        return redirect()->route('partner.campaigns.show', $campaign->id)->with('status', __('Campaign sent for review. The fee is charged only when it goes live.'));
    }

    public function show(int $campaign): View
    {
        $campaign = $this->campaign($campaign)->load('listing.variant.product', 'invitations.marketer', 'samples', 'contentSubmissions', 'transitions', 'requester');

        return view('panels.partner.campaigns.show', [
            'campaign' => $campaign,
            'remaining' => $campaign->budget === null ? null : $this->campaigns->remainingBudget($campaign),
            'conversions' => $campaign->conversions()->selectRaw('status, count(*) as total, sum(gross) as gross')->groupBy('status')->get(),
            'influencers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)->whereJsonContains('job_types', 'influencer')->orderBy('display_name')->get(),
        ]);
    }

    public function cancel(Request $request, int $campaign): RedirectResponse
    {
        $this->campaigns->cancel($this->campaign($campaign), ActorType::Vendor, $request->user()->id, $request->input('reason'));

        return back()->with('status', __('Campaign cancelled.'));
    }

    public function pause(Request $request, int $campaign): RedirectResponse
    {
        $this->campaigns->pause($this->campaign($campaign), ActorType::Vendor, $request->user()->id, 'Paused by the store');

        return back()->with('status', __('Campaign paused.'));
    }

    public function resume(Request $request, int $campaign): RedirectResponse
    {
        $this->campaigns->resume($this->campaign($campaign), ActorType::Vendor, $request->user()->id);

        return back()->with('status', __('Campaign resumed.'));
    }

    public function invite(Request $request, int $campaign, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate(['influencer_ids' => ['required', 'array'], 'influencer_ids.*' => ['integer']]);
        $skipped = $invitations->inviteMore($this->campaign($campaign), $data['influencer_ids']);

        return back()->with('status', $skipped === [] ? __('Invitations sent.') : __('Some marketers were skipped: :reasons', ['reasons' => implode('; ', array_unique($skipped))]));
    }

    public function approveRequest(Request $request, int $campaign, CampaignWizard $wizard): RedirectResponse
    {
        $data = $request->validate(['budget' => ['required', 'numeric', 'gt:0'], 'ends_at' => ['nullable', 'date', 'after:now']]);

        try {
            $wizard->approveRequest($this->campaign($campaign), (string) $data['budget'], $request->user()->id, $data['ends_at'] ?? null);
        } catch (CampaignRefused $refused) {
            return back()->withErrors([$refused->field => $refused->getMessage()]);
        }

        return back()->with('status', __('Request approved — the campaign is live.'));
    }

    public function rejectRequest(Request $request, int $campaign): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->campaigns->reject($this->campaign($campaign), ActorType::Vendor, $request->user()->id, $data['reason']);

        return back()->with('status', __('Request rejected.'));
    }

    public function shipSample(Request $request, int $sample, Samples $samples): RedirectResponse
    {
        $sample = $this->sample($sample);
        $samples->ship($sample, ActorType::Vendor, $request->user()->id, $request->input('tracking_number'));

        return back()->with('status', __('Sample shipped.'));
    }

    public function reviewContent(Request $request, int $submission, ContentSubmissions $content): RedirectResponse
    {
        $submission = ContentSubmission::query()->whereIn('campaign_id', MarketerCampaign::query()->where('vendor_id', $this->workspace->vendor()->id)->select('id'))->findOrFail($submission);

        $request->input('decision') === 'approve'
            ? $content->approve($submission, ActorType::Vendor, $request->user()->id)
            : $content->reject($submission, ActorType::Vendor, $request->user()->id, (string) $request->input('reason'));

        return back()->with('status', __('Content reviewed.'));
    }

    private function campaign(int $id): MarketerCampaign
    {
        return MarketerCampaign::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
    }

    private function sample(int $id): CampaignSample
    {
        return CampaignSample::query()->whereIn('campaign_id', MarketerCampaign::query()->where('vendor_id', $this->workspace->vendor()->id)->select('id'))->findOrFail($id);
    }
}
