<?php

namespace App\Domains\Advertising\Http\Controllers\Seller;

use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Advertising (SRS §10): sponsored-product campaigns
 * (CPC / CPM), paid slot bookings and coupon participation requests. Every
 * query is scoped to the workspace store; charges land on its statement.
 */
class SellerAdvertisingController extends Controller
{
    /**
     * Statuses the store may move a campaign out of (review decisions are Nawy's).
     *
     * @var array<int, AdCampaignStatus>
     */
    public const SELLER_MOVABLE = [AdCampaignStatus::Active, AdCampaignStatus::Paused, AdCampaignStatus::BudgetExhausted];

    public function __construct(private VendorWorkspace $workspace) {}

    public function index(): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.advertising', [
            'campaigns' => AdCampaign::query()->where(['advertiser_type' => 'vendor', 'advertiser_id' => $vendor->id])->with('listing.variant.product')->latest('id')->get(),
            'bookings' => AdBooking::query()->where(['advertiser_type' => 'vendor', 'advertiser_id' => $vendor->id])->with('slot')->latest('id')->get(),
            'listings' => $vendor->listings()->where('status', ListingStatus::Active)->with('variant.product')->get(),
            'slots' => AdSlot::query()->bookable()->where('is_active', true)->orderBy('name')->get(),
            'invitations' => CouponParticipationInvitation::query()->where('status', ParticipationInvitationStatus::Open)->with('coupon')->get(),
        ]);
    }

    public function storeCampaign(Request $request, AdCampaigns $campaigns): RedirectResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'pricing_model' => ['required', 'in:cpc,cpm'],
            'bid' => ['required', 'numeric', 'gt:0'],
            'daily_budget' => ['required', 'numeric', 'gt:0'],
            'total_budget' => ['nullable', 'numeric', 'gt:0'],
            'keywords' => ['nullable', 'string', 'max:500'],
        ]);
        $data['keywords'] = array_filter(array_map('trim', explode(',', (string) ($data['keywords'] ?? ''))));
        $campaign = $campaigns->create($this->workspace->vendor(), $data, $request->user()->id);
        $campaigns->submit($campaign, $request->user()->id);

        return back()->with('status', __('Campaign sent for review.'));
    }

    public function moveCampaign(Request $request, int $campaign, AdCampaigns $campaigns): RedirectResponse
    {
        $to = AdCampaignStatus::from($request->validate(['status' => ['required', 'in:active,paused,ended']])['status']);
        $campaign = AdCampaign::query()->where(['advertiser_type' => 'vendor', 'advertiser_id' => $this->workspace->vendor()->id])->findOrFail($campaign);

        // Browser test BT-VEN-003: only Nawy's ad review takes a campaign live; the store pauses, resumes and ends live ones.
        if (! in_array($campaign->status, self::SELLER_MOVABLE, true)) {
            return back()->withErrors(['action' => __('Nawy reviews the campaign first; you can pause or end it once it is live.')]);
        }

        $campaigns->move($campaign, $to, ActorType::Vendor, $request->user()->id);

        return back()->with('status', __('Campaign updated.'));
    }

    public function storeBooking(Request $request, AdBookings $bookings): RedirectResponse
    {
        $data = $request->validate([
            'ad_slot_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'headline' => ['nullable', 'string', 'max:120'],
            'creative' => ['required', 'image', 'max:4096'],
        ]);
        $slot = AdSlot::query()->bookable()->findOrFail($data['ad_slot_id']);
        $booking = $bookings->draft($slot, AdvertiserBilling::VENDOR, $this->workspace->vendor()->id, $data, $request->file('creative'), $request->user()->id);
        $bookings->submit($booking, $request->user()->id);

        return back()->with('status', __('Booking paid and sent for creative review.'));
    }

    public function joinCoupon(Request $request, int $invitation, CouponParticipations $participations): RedirectResponse
    {
        $data = $request->validate(['offered_fee' => ['required', 'numeric', 'min:0'], 'payment_method' => ['required', 'in:wallet,bank_transfer'], 'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192']]);
        $participations->request(CouponParticipationInvitation::query()->findOrFail($invitation), AdvertiserBilling::VENDOR, $this->workspace->vendor()->id, (string) $data['offered_fee'], $data['payment_method'], $request->file('proof'), $request->user()->id);

        return back()->with('status', __('Request sent to Nawy.'));
    }
}
