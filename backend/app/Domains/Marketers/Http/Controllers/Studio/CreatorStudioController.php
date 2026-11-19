<?php

namespace App\Domains\Marketers\Http\Controllers\Studio;

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\CampaignSample;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Campaigns\Support\CampaignRefused;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\ContentSubmissions;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Campaigns\Support\ReferralCodes;
use App\Domains\Campaigns\Support\ReferralQr;
use App\Domains\Campaigns\Support\Samples;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Models\MarketerWithdrawal;
use App\Domains\Marketers\Support\CrossPromotion;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Marketers\Support\Withdrawals;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creator Studio (marketer., Figma 90:2452, 90:2785, 101:9581, 101:10009,
 * 101:10450): overview, invitations (accept / decline), campaigns and
 * campaign detail (onboarding contract, sample tracker, referral card,
 * content), open campaigns to join (origin F), links & codes, wallet and
 * withdrawals, cross-promotion with peer promotion (origin E), onboarding
 * contract, agency switcher. Every query is scoped to the workspace marketer.
 */
class CreatorStudioController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace, private CountryContext $countryContext, private MoneyFactory $money) {}

    public function overview(Request $request, ContractGates $gates): View
    {
        if (! $this->workspace->has()) {
            return app(PanelDashboardController::class)($request);
        }

        $marketer = $this->workspace->marketer();
        $since = now()->subDays(30);
        $currency = $this->countryContext->current()->currency;
        $conversions = MarketerConversion::query()->where('marketer_profile_id', $marketer->id)->where('created_at', '>=', $since);
        $daily = (clone $conversions)->where('status', '!=', ConversionStatus::Reversed)->selectRaw('DATE(created_at) as day, SUM(net + bonus) as total')->groupBy('day')->pluck('total', 'day');

        return view('panels.marketer.overview', [
            'marketer' => $marketer,
            'currency' => $currency,
            'earnings' => $this->money->of((string) (clone $conversions)->where('status', '!=', ConversionStatus::Reversed)->sum('net'), $currency),
            'clicks' => ReferralClick::query()->where('marketer_profile_id', $marketer->id)->where('clicked_at', '>=', $since)->count(),
            'orders' => (clone $conversions)->distinct('order_id')->count('order_id'),
            'clawback' => app(Withdrawals::class)->balances($marketer, $currency)['clawback']->negated(),
            'daily' => collect(range(13, 0))->map(fn (int $daysAgo): array => ['day' => now()->subDays($daysAgo)->toDateString(), 'total' => (string) ($daily[now()->subDays($daysAgo)->toDateString()] ?? '0')]),
            'invitations' => $marketer->invitations()->with('campaign.vendor')->latest('id')->limit(5)->get(),
            'contractAccepted' => $gates->marketerContractAccepted($marketer),
            'topCode' => $marketer->invitations()->where('status', InvitationStatus::Accepted)->latest('id')->first(),
        ]);
    }

    public function invitations(): View
    {
        return view('panels.marketer.invitations', [
            'invitations' => $this->workspace->marketer()->invitations()->with('campaign.listing.variant.product', 'campaign.vendor')->latest('id')->paginate(20),
        ]);
    }

    public function accept(Request $request, int $invitation, Invitations $invitations): RedirectResponse
    {
        $invitations->accept($this->invitation($invitation), $request->user()->id, $request->input('note'));

        return back()->with('status', __('Invitation accepted. Your referral link is ready.'));
    }

    public function decline(Request $request, int $invitation, Invitations $invitations): RedirectResponse
    {
        $invitations->reject($this->invitation($invitation), $request->user()->id, $request->input('reason'));

        return back()->with('status', __('Invitation declined.'));
    }

    public function campaigns(): View
    {
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.campaigns', [
            'invitations' => $marketer->invitations()->where('status', InvitationStatus::Accepted)->with('campaign.listing.variant.product')->latest('id')->get(),
            'requests' => MarketerCampaign::query()->where('requested_by_marketer_id', $marketer->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function campaign(int $invitation, ContractGates $gates, ReferralCodes $codes): View
    {
        $invitation = $this->invitation($invitation)->load('campaign.listing.variant.product', 'campaign.vendor');
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.campaign', [
            'invitation' => $invitation,
            'campaign' => $invitation->campaign,
            'share' => $codes->share($invitation->referral_code, $this->countryContext->current()),
            'samples' => CampaignSample::query()->where('invitation_id', $invitation->id)->get(),
            'content' => $invitation->campaign->contentSubmissions()->where('invitation_id', $invitation->id)->latest('id')->get(),
            'conversions' => MarketerConversion::query()->where('invitation_id', $invitation->id)->latest('id')->limit(20)->get(),
            'clicks' => ReferralClick::query()->where('invitation_id', $invitation->id)->count(),
            'contractAccepted' => $gates->marketerContractAccepted($marketer),
        ]);
    }

    public function requestSample(Request $request, int $sample, Samples $samples): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:32'], 'city' => ['required', 'string', 'max:120'], 'street' => ['required', 'string', 'max:255']]);
        $samples->request(CampaignSample::query()->findOrFail($sample), $this->workspace->marketer()->id, $data, $request->user()->id);

        return back()->with('status', __('Address sent. The sample ships soon.'));
    }

    public function confirmSample(Request $request, int $sample, Samples $samples): RedirectResponse
    {
        $samples->confirmReceipt(CampaignSample::query()->findOrFail($sample), $this->workspace->marketer()->id, $request->user()->id);

        return back()->with('status', __('Receipt confirmed. Your review is due within the review window.'));
    }

    public function submitContent(Request $request, int $invitation, ContentSubmissions $content): RedirectResponse
    {
        $data = $request->validate(['platform' => ['required', 'string', 'max:24'], 'url' => ['required', 'url', 'max:500'], 'caption' => ['nullable', 'string', 'max:2000']]);
        $content->submit($this->invitation($invitation), $data['platform'], $data['url'], $data['caption'] ?? null, $request->user()->id);

        return back()->with('status', __('Content submitted for review.'));
    }

    public function marketplace(): View
    {
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.marketplace', [
            'campaigns' => MarketerCampaign::query()->whereIn('status', [CampaignStatus::Active, CampaignStatus::AutoApproved])->where('open_to_affiliates', true)
                ->whereDoesntHave('invitations', fn ($query) => $query->where('marketer_profile_id', $marketer->id))
                ->with('listing.variant.product')->latest('id')->paginate(20),
        ]);
    }

    public function join(Request $request, int $campaign, Invitations $invitations): RedirectResponse
    {
        $joined = $invitations->joinOpen(MarketerCampaign::query()->findOrFail($campaign), $this->workspace->marketer());

        return redirect()->route('marketer.campaigns.show', $joined->id)->with('status', __('You joined the campaign.'));
    }

    public function requestCampaign(Request $request, CampaignWizard $wizard): RedirectResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $wizard->requestByMarketer($this->workspace->marketer(), Listing::query()->with('variant.product')->findOrFail($data['listing_id']), $data['title'], $data['notes'] ?? null, $request->user()->id);
        } catch (CampaignRefused $refused) {
            return back()->withErrors([$refused->field => $refused->getMessage()]);
        }

        return back()->with('status', __('Request sent to the store.'));
    }

    public function links(ReferralCodes $codes): View
    {
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.links', [
            'links' => $marketer->invitations()->where('status', InvitationStatus::Accepted)->with('campaign.listing.variant.product')->latest('id')->get()
                ->map(fn (CampaignInvitation $invitation): array => [
                    'invitation' => $invitation,
                    'share' => $codes->share($invitation->referral_code, $this->countryContext->current()),
                    'clicks' => ReferralClick::query()->where('invitation_id', $invitation->id)->count(),
                    'orders' => MarketerConversion::query()->where('invitation_id', $invitation->id)->distinct('order_id')->count('order_id'),
                ]),
        ]);
    }

    /**
     * D16: the QR code of one of the marketer's referral links (SVG or PNG
     * download; `inline=1` shows the SVG preview on the Links page).
     */
    public function linkQr(Request $request, int $invitation, ReferralCodes $codes, ReferralQr $qr): Response
    {
        $row = $this->workspace->marketer()->invitations()->where('status', InvitationStatus::Accepted)->whereNotNull('referral_code')->findOrFail($invitation);
        $format = $request->validate(['format' => ['nullable', 'in:svg,png']])['format'] ?? 'svg';

        return $qr->response($codes->share($row->referral_code, $this->countryContext->current())['qr_payload'], $row->referral_code, $format, ! $request->boolean('inline'));
    }

    public function wallet(Withdrawals $withdrawals): View
    {
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.wallet', [
            'currency' => $this->countryContext->current()->currency,
            'balances' => $withdrawals->balances($marketer, $this->countryContext->current()->currency),
            'withdrawals' => MarketerWithdrawal::query()->where('marketer_profile_id', $marketer->id)->latest('id')->paginate(10),
            'conversions' => MarketerConversion::query()->where('marketer_profile_id', $marketer->id)->with('campaign')->latest('id')->limit(20)->get(),
        ]);
    }

    public function withdraw(Request $request, Withdrawals $withdrawals): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'iban' => ['required', 'string', 'max:34'], 'account_name' => ['required', 'string', 'max:120']]);
        $currency = $this->countryContext->current()->currency;
        $withdrawals->request($this->workspace->marketer(), $this->money->of((string) $data['amount'], $currency), ['iban' => $data['iban'], 'account_name' => $data['account_name']], $request->user()->id);

        return back()->with('status', __('Withdrawal requested.'));
    }

    public function crossPromotion(CrossPromotion $crossPromotion): View
    {
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.cross-promotion', [
            'enabled' => $crossPromotion->enabled(),
            'rows' => $crossPromotion->dashboard($marketer),
            'peerCampaigns' => MarketerCampaign::query()->where('origin', CampaignOrigin::Peer)->where(fn ($query) => $query->where('requested_by_marketer_id', $marketer->id)->orWhere('owner_id', $marketer->id))->with('listing.variant.product')->latest('id')->limit(20)->get(),
            'marketer' => $marketer,
        ]);
    }

    public function peer(Request $request, CampaignWizard $wizard): RedirectResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'integer']]);

        try {
            $wizard->peerPromotion($this->workspace->marketer(), Listing::query()->with('variant.product')->findOrFail($data['listing_id']), $request->user()->id);
        } catch (CampaignRefused $refused) {
            return back()->withErrors([$refused->field => $refused->getMessage()]);
        }

        return back()->with('status', __('Peer promotion started.'));
    }

    public function contract(ContractResolver $resolver, ContractRenderer $renderer, ContractGates $gates): View
    {
        $version = $resolver->current(ContractKind::MarketerOnboarding, $this->countryContext->current());
        $marketer = $this->workspace->marketer();

        return view('panels.marketer.contract', [
            'version' => $version,
            'text' => $version === null ? null : $renderer->text($version, app()->getLocale(), ['marketer_name' => $marketer->display_name]),
            'accepted' => $gates->marketerContractAccepted($marketer),
            'marketer' => $marketer,
        ]);
    }

    public function signContract(Request $request, ContractResolver $resolver, ContractSigner $signer): RedirectResponse
    {
        $data = $request->validate(['full_name' => ['required', 'string', 'max:160'], 'accept' => ['accepted']]);
        $version = $resolver->current(ContractKind::MarketerOnboarding, $this->countryContext->current()) ?? abort(404);
        $marketer = $this->workspace->marketer();
        $signer->sign($version, SignerType::Marketer, $marketer->id, $request->user()->id, $data['full_name'], SignatureMethod::Click, $request->ip(), $request->userAgent(), locale: app()->getLocale(), variables: ['marketer_name' => $marketer->display_name]);

        return redirect()->route('marketer.dashboard')->with('status', __('Contract accepted.'));
    }

    public function switchMarketer(Request $request, MarketerProfiles $profiles): RedirectResponse
    {
        $id = $request->integer('marketer_id');
        abort_unless($profiles->agencyMarketers($request->user())->contains('id', $id), 404);
        $request->session()->put('acting_marketer_id', $id);

        return redirect()->route('marketer.dashboard');
    }

    private function invitation(int $id): CampaignInvitation
    {
        return CampaignInvitation::query()->where('marketer_profile_id', $this->workspace->marketer()->id)->findOrFail($id);
    }
}
