<?php

namespace App\Domains\Advertising\Http\Controllers\Admin;

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Advertising\Enums\SubscriptionStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPackageSubscription;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Models\CouponParticipationRequest;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\AdPackageSubscriptions;
use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Models\Coupon;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Marketing & ads (SRS §10): review queues for sponsored-product
 * campaigns, ad slot bookings (creative review) and bank-transfer ad
 * packages; coupon participation invitations and their requests.
 */
class AdsReviewController extends Controller
{
    public function index(): View
    {
        return view('panels.admin.marketing.ads', [
            'campaigns' => AdCampaign::query()->where('status', AdCampaignStatus::PendingReview)->with('listing.variant.product')->oldest()->get(),
            'bookings' => AdBooking::query()->where('status', AdBookingStatus::PendingReview)->with('slot')->orderBy('starts_on')->get(),
            'subscriptions' => AdPackageSubscription::query()->where('status', SubscriptionStatus::Pending)->with('package')->oldest()->get(),
        ]);
    }

    public function decideCampaign(Request $request, int $campaign, AdCampaigns $campaigns): RedirectResponse
    {
        $data = $this->decision($request);
        $campaign = AdCampaign::query()->findOrFail($campaign);
        $data['decision'] === 'approve' ? $campaigns->approve($campaign, $request->user()->id) : $campaigns->reject($campaign, $request->user()->id, (string) $data['reason']);

        return back()->with('status', __('Campaign reviewed.'));
    }

    public function decideBooking(Request $request, int $booking, AdBookings $bookings): RedirectResponse
    {
        $data = $this->decision($request);
        $booking = AdBooking::query()->findOrFail($booking);
        $data['decision'] === 'approve' ? $bookings->approve($booking, $request->user()->id) : $bookings->reject($booking, $request->user()->id, (string) $data['reason']);

        return back()->with('status', __('Booking reviewed.'));
    }

    /**
     * QC-018 (ADR 11): the admin uploads the sponsored video creative on a booking.
     */
    public function bookingVideo(Request $request, int $booking, AdBookings $bookings): RedirectResponse
    {
        $video = $request->validate(['video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200']])['video'];

        try {
            $bookings->attachVideo(AdBooking::query()->findOrFail($booking), $video, $request->user()->id);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Video creative uploaded.'));
    }

    public function decideSubscription(Request $request, int $subscription, AdPackageSubscriptions $subscriptions): RedirectResponse
    {
        $data = $this->decision($request);
        $subscription = AdPackageSubscription::query()->findOrFail($subscription);
        $data['decision'] === 'approve' ? $subscriptions->approve($subscription, $request->user()->id) : $subscriptions->reject($subscription, $request->user()->id, (string) $data['reason']);

        return back()->with('status', __('Subscription reviewed.'));
    }

    public function participation(): View
    {
        return view('panels.admin.marketing.coupon-participation', [
            'invitations' => CouponParticipationInvitation::query()->with(['coupon', 'requests'])->latest('id')->paginate(20),
            'coupons' => Coupon::query()->where('scope', 'platform')->orderBy('code')->get(),
            'country' => app(CountryContext::class)->current(),
        ]);
    }

    public function storeInvitation(Request $request, CouponParticipations $participations): RedirectResponse
    {
        $data = $request->validate(['coupon_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:160'], 'min_fee' => ['required', 'numeric', 'min:0'], 'capacity' => ['required', 'integer', 'min:1'], 'closes_at' => ['required', 'date'], 'open' => ['sometimes', 'boolean']]);
        $closesAt = app(CountryClock::class)->localToUtc(app(CountryContext::class)->current(), $data['closes_at']);
        $invitation = $participations->create(Coupon::query()->findOrFail($data['coupon_id']), ['title' => $data['title'], 'min_fee' => (string) $data['min_fee'], 'capacity' => (int) $data['capacity'], 'closes_at' => $closesAt->toDateTimeString()], $request->user()->id);

        if ($request->boolean('open')) {
            $participations->open($invitation, $request->user()->id);
        }

        return back()->with('status', __('Invitation saved.'));
    }

    public function decideRequest(Request $request, int $participation, CouponParticipations $participations): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,confirm,reject'], 'reason' => ['nullable', 'string', 'max:255']]);
        $participationRequest = CouponParticipationRequest::query()->findOrFail($participation);

        match ($data['decision']) {
            'approve' => $participations->approve($participationRequest, $request->user()->id),
            'confirm' => $participations->confirmPayment($participationRequest, $request->user()->id),
            'reject' => $participations->reject($participationRequest, $request->user()->id, $data['reason'] ?? null),
        };

        return back()->with('status', __('Request updated.'));
    }

    /**
     * @return array{decision: string, reason: string|null}
     */
    private function decision(Request $request): array
    {
        return $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:255']]) + ['reason' => null];
    }
}
