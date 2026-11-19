<?php

namespace App\Shared\Notifications\Email\Listeners;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Support\FinanceSettings;
use App\Domains\Membership\Enums\MembershipStatus;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Support\Memberships;
use App\Domains\Panels\Support\Panel;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Models\User;
use App\Shared\Localization\InLocale;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\StateMachine\StateMoved;
use Throwable;

/**
 * Catalogue emails driven by lifecycle moves of accounts, memberships, wallets, gift cards, support, Open Souq and travel bookings
 * (docs/project/email-catalogue.md). Sends are deferred until the move's
 * transaction commits ({@see Emails::send()}).
 */
class AccountLifecycleEmails
{
    public function __construct(
        private Emails $emails,
        private EmailLinks $links,
        private CountryContext $countryContext,
        private FinanceSettings $financeSettings,
    ) {}

    public function handle(StateMoved $event): void
    {
        try {
            match (true) {
                $event->subject instanceof Membership => $this->membershipMoved($event->subject, $event),
                $event->subject instanceof ClassifiedAd => $this->classifiedAd($event->subject, $event),
                $event->subject instanceof TravelPackage => $this->travelPackage($event->subject, $event),
                $event->subject instanceof TravelPromotion => $this->travelPromotion($event->subject, $event),
                default => null,
            };
        } catch (Throwable $exception) {
            // An email must never break the lifecycle move that triggered it.
            report($exception);
        }
    }

    /**
     * Nawy One: first paid period (started), paid renewal (renewed), failed
     * renewal (past_due) and the end of a membership (cancelled / expired).
     */
    private function membershipMoved(Membership $membership, StateMoved $event): void
    {
        match (true) {
            $event->to === MembershipStatus::Active && $event->from === MembershipStatus::Incomplete => $this->membership($membership, 'membership.started'),
            $event->to === MembershipStatus::Active => $this->membership($membership, 'membership.renewed'),
            $event->to === MembershipStatus::PastDue => $this->membership($membership, 'membership.payment_failed'),
            in_array($event->to, [MembershipStatus::Cancelled, MembershipStatus::Expired], true) => $this->membership($membership, 'membership.cancelled'),
            default => null,
        };
    }

    /**
     * One Nawy One email to the member, once per membership period (also
     * called by {@see Memberships} for a trial start and an auto-renew cancel).
     */
    public function membership(Membership $membership, string $key): void
    {
        $customer = User::query()->find($membership->customer_user_id);

        if ($customer === null) {
            return;
        }

        $plan = $membership->plan;
        $invoice = $membership->invoices()->latest('id')->first();
        $currency = $invoice->currency ?? ($this->countryContext->has() ? $this->countryContext->current()->currency : 'AED');
        $graceDays = (int) $this->financeSettings->get('subscription.grace_days', 'membership_grace_days');

        $this->emails->send($key, $customer, [
            'plan' => $plan === null ? '' : InLocale::run($customer->preferredLocale(), fn (): string => $plan->name()),
            'amount' => EmailValue::money((string) ($invoice->amount ?? $plan?->price ?? '0'), (string) $currency),
            'renews_on' => EmailValue::date($membership->current_period_end),
            'ends_on' => EmailValue::date($membership->current_period_end ?? now()),
            'grace_until' => EmailValue::date(($membership->past_due_since ?? now())->addDays($graceDays)),
        ], [
            'entity' => $membership,
            // Once per period: a later period (or a new membership on the same row) mails again.
            'key' => (string) ($key === 'membership.payment_failed' ? $membership->past_due_since?->getTimestamp() : $membership->current_period_end?->getTimestamp()),
            'url' => $this->links->storefront('account/membership', $customer->preferredLocale()),
        ]);
    }

    /**
     * Open Souq ad reviewed (approved / rejected) or expired — to the ad owner.
     */
    private function classifiedAd(ClassifiedAd $ad, StateMoved $event): void
    {
        $eventKey = match (true) {
            $event->to === ClassifiedAdStatus::Active && $event->from === ClassifiedAdStatus::PendingReview => 'classified.approved',
            $event->to === ClassifiedAdStatus::Rejected => 'classified.rejected',
            $event->to === ClassifiedAdStatus::Expired => 'classified.expired',
            default => null,
        };
        $owner = $eventKey === null ? null : User::query()->find($ad->owner_user_id);

        if ($owner === null) {
            return;
        }

        $locale = $owner->preferredLocale();

        $this->emails->send($eventKey, $owner, [
            'ad_title' => (string) $ad->title,
            'ad_number' => (string) $ad->number,
            'expires' => EmailValue::date($ad->expires_at),
            'reason' => (string) ($ad->rejection_reason ?? $event->reason ?? ''),
        ], [
            'entity' => $ad,
            'key' => $eventKey === 'classified.rejected' ? 'rejected' : (string) $ad->expires_at?->getTimestamp(),
            'url' => $eventKey === 'classified.approved' ? $this->links->storefront('classified/find/'.$ad->number, $locale) : $this->links->storefront('account/my-ads', $locale),
        ]);
    }

    /**
     * Travel package review result — to the agency owner.
     */
    private function travelPackage(TravelPackage $package, StateMoved $event): void
    {
        $key = match (true) {
            $event->to === PackageStatus::Active && $event->from === PackageStatus::PendingReview => 'travel.package_approved',
            $event->to === PackageStatus::Rejected => 'travel.package_rejected',
            default => null,
        };
        $owner = $key === null ? null : User::query()->find($package->vendor?->owner_user_id);

        if ($owner === null) {
            return;
        }

        $this->emails->send($key, $owner, [
            'trip' => InLocale::run($owner->preferredLocale(), fn (): string => $package->localizedTitle()),
            'reason' => (string) ($package->rejection_reason ?? $event->reason ?? ''),
        ], [
            'entity' => $package,
            'key' => $event->to->value.':'.now()->getTimestamp(),
            'panel' => 'travel_agency',
            'url' => Panel::find('travel_agency')->url('packages/'.$package->id),
        ]);
    }

    /**
     * Paid travel promotion: live, ended, or never paid.
     */
    private function travelPromotion(TravelPromotion $promotion, StateMoved $event): void
    {
        $key = match (true) {
            $event->to === TravelPromotionStatus::Active => 'travel.promotion_active',
            $event->to === TravelPromotionStatus::Expired => 'travel.promotion_expired',
            $event->to === TravelPromotionStatus::Cancelled && $event->from === TravelPromotionStatus::PendingPayment => 'travel.promotion_failed',
            default => null,
        };
        $recipient = $key === null ? null : User::query()->find($promotion->bought_by_user_id ?? $promotion->vendor?->owner_user_id);

        if ($recipient === null) {
            return;
        }

        $promotion->loadMissing(['package', 'product']);

        InLocale::run($recipient->preferredLocale(), fn () => $this->emails->send($key, $recipient, [
            'promotion' => (string) ($promotion->product?->localizedName() ?? $promotion->number),
            'trip' => (string) $promotion->package?->localizedTitle(),
            'ends' => EmailValue::date($promotion->ends_at),
            'reason' => EmailValue::t($event->reason ?? 'Payment not completed'),
        ], [
            'entity' => $promotion,
            'panel' => 'travel_agency',
            'url' => Panel::find('travel_agency')->url('promotions'),
        ]));
    }
}
