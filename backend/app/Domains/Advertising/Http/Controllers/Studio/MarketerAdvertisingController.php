<?php

namespace App\Domains\Advertising\Http\Controllers\Studio;

use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdPackage;
use App\Domains\Advertising\Models\AdPackageSubscription;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Models\CouponParticipationRequest;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdPackageSubscriptions;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Advertising\Support\CouponParticipations;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Creator Studio → Advertising (QC-010, SRS §10 / §11.13, lifecycle §4.6,
 * §4.8, §4.9 with the marketer as payer): ad packages (wallet or bank
 * transfer), paid slot bookings and coupon participation requests. Charges
 * come out of the marketer's wallet (`AdvertiserBilling::MARKETER`).
 */
class MarketerAdvertisingController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace) {}

    public function index(): View
    {
        $marketerId = $this->workspace->marketer()->id;

        return view('panels.marketer.advertising', [
            'packages' => AdPackage::query()->where('is_active', true)->orderBy('price')->get(),
            'subscriptions' => AdPackageSubscription::query()->where(['subscriber_type' => AdvertiserBilling::MARKETER, 'subscriber_id' => $marketerId])->with('package')->latest('id')->get(),
            'slots' => AdSlot::query()->bookable()->where('is_active', true)->orderBy('name')->get(),
            'bookings' => AdBooking::query()->where(['advertiser_type' => AdvertiserBilling::MARKETER, 'advertiser_id' => $marketerId])->with('slot')->latest('id')->get(),
            'invitations' => CouponParticipationInvitation::query()->where('status', ParticipationInvitationStatus::Open)->with('coupon')->get(),
            'currency' => app(CountryContext::class)->current()->currency,
            'requests' => CouponParticipationRequest::query()->where(['participant_type' => AdvertiserBilling::MARKETER, 'participant_id' => $marketerId])->with('invitation')->latest('id')->get(),
        ]);
    }

    public function subscribe(Request $request, int $package, AdPackageSubscriptions $subscriptions): RedirectResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'in:wallet,bank_transfer'], 'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192']]);

        return $this->attempt(fn () => $subscriptions->subscribe(AdPackage::query()->findOrFail($package), $this->workspace->marketer()->id, $data['payment_method'], $request->file('proof'), $request->user()->id),
            __('Package requested.'));
    }

    public function book(Request $request, AdBookings $bookings): RedirectResponse
    {
        $data = $request->validate([
            'ad_slot_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'headline' => ['nullable', 'string', 'max:120'],
            'creative' => ['required', 'image', 'max:4096'],
        ]);

        return $this->attempt(function () use ($bookings, $data, $request): void {
            $booking = $bookings->draft(AdSlot::query()->bookable()->findOrFail($data['ad_slot_id']), AdvertiserBilling::MARKETER, $this->workspace->marketer()->id, $data, $request->file('creative'), $request->user()->id);
            $bookings->submit($booking, $request->user()->id);
        }, __('Booking paid from your wallet and sent for creative review.'));
    }

    public function joinCoupon(Request $request, int $invitation, CouponParticipations $participations): RedirectResponse
    {
        $data = $request->validate(['offered_fee' => ['required', 'numeric', 'min:0'], 'payment_method' => ['required', 'in:wallet,bank_transfer'], 'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192']]);

        return $this->attempt(fn () => $participations->request(CouponParticipationInvitation::query()->findOrFail($invitation), AdvertiserBilling::MARKETER, $this->workspace->marketer()->id, (string) $data['offered_fee'], $data['payment_method'], $request->file('proof'), $request->user()->id),
            __('Request sent to Nawy.'));
    }

    private function attempt(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
