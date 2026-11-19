<?php

namespace App\Shared\Notifications\Email\Listeners;

use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Catalog\Models\ProductRequest;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Fulfilment\Models\RemovalOrder;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Models\MarketerWithdrawal;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Panels\Support\Panel;
use App\Domains\Vendors\Enums\ChangeRequestSection;
use App\Domains\Vendors\Models\ChangeRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\StateMoved;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Catalogue emails driven by lifecycle moves of sellers, travel agencies, marketers, delivery agents and admin alerts
 * (docs/project/email-catalogue.md). Sends are deferred until the move's
 * transaction commits ({@see Emails::send()}); each is sent once per
 * subject, status and recipient.
 */
class PartnerLifecycleEmails
{
    public function __construct(private Emails $emails, private PartnerEmails $partners) {}

    public function handle(StateMoved $event): void
    {
        try {
            $subject = $event->subject;
            $to = $event->to->value;

            match (true) {
                $subject instanceof PartnerApplication => $to === 'submitted' ? $this->applicationSubmitted($subject) : null,
                $subject instanceof RemovalOrder => $to === 'completed' ? $this->removalCompleted($subject) : null,
                $subject instanceof VendorPayout => $this->payout($subject, $to, $event->reason),
                $subject instanceof ProductRequest => $this->productRequest($subject, $to, $event->reason),
                $subject instanceof ChangeRequest => $this->changeRequest($subject, $to, $event->reason),
                $subject instanceof AdCampaign => $this->adCampaign($subject, $event),
                $subject instanceof AdBooking => $this->adBooking($subject, $to, $event->reason),
                $subject instanceof CouponParticipationInvitation => $to === 'open' ? $this->couponInvitation($subject) : null,
                $subject instanceof Document => $this->document($subject, $to, $event->reason),
                $subject instanceof MarketerConversion => $to === 'released' ? $this->earningsReleased($subject) : null,
                $subject instanceof MarketerWithdrawal => $this->marketerWithdrawal($subject, $to, $event->reason),
                $subject instanceof DeliveryWithdrawal => $this->deliveryWithdrawal($subject, $to, $event->reason),
                default => null,
            };
        } catch (Throwable $exception) {
            // An email must never break the lifecycle move that triggered it.
            report($exception);
        }
    }

    private function applicationSubmitted(PartnerApplication $application): void
    {
        $company = collect([
            $application->step('store')['store_name'] ?? null,
            $application->step('agency')['agency_name'] ?? null,
            $application->step('company')['legal_name'] ?? null,
            $application->step('profile')['display_name'] ?? null,
        ])->first(fn ($value): bool => is_string($value) && $value !== '');

        $this->emails->alertAdmins('admin.partner_application', [
            'application_number' => $application->number,
            'type' => EmailValue::t($application->type->labelKey()),
            'company' => (string) ($company ?? $application->name),
        ], ['entity' => $application, 'url' => Panel::find('admin')->url('/applications/'.$application->id)]);
    }

    private function removalCompleted(RemovalOrder $removal): void
    {
        $this->partners->toVendor((int) $removal->vendor_id, 'fbn.removal_completed', [
            'removal_number' => (string) ($removal->number ?: '#'.$removal->id),
            'units' => (string) $removal->lines()->sum('quantity'),
        ], '/inventory/removals', $this->once($removal, 'completed'));
    }

    private function payout(VendorPayout $payout, string $to, ?string $reason): void
    {
        $event = match ($to) {
            'approved' => 'payout.approved',
            'completed' => 'payout.paid',
            'failed', 'on_hold' => 'payout.failed',
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->partners->toVendor((int) $payout->vendor_id, $event, [
            'payout_number' => (string) $payout->number,
            'amount' => EmailValue::money((string) $payout->total, $payout->currency),
            'reference' => (string) $payout->bank_reference,
            'reason' => (string) ($reason ?? ($to === 'on_hold' ? $payout->hold_reason : $payout->failure_reason) ?? ''),
        ], '/payouts/'.$payout->id, $this->once($payout, $to));
    }

    private function productRequest(ProductRequest $request, string $to, ?string $reason): void
    {
        if (! in_array($to, ['approved', 'rejected'], true)) {
            return;
        }

        $variables = fn (string $locale): array => [
            'request_title' => (string) ($locale === 'ar' ? ($request->title_ar ?: $request->title_en) : ($request->title_en ?: $request->title_ar)),
            'decision' => EmailValue::t(ucfirst($to)),
            'note' => (string) ($reason ?? $request->rejection_reason ?? ''),
        ];

        if ($request->requester_type !== ProductRequest::REQUESTER_VENDOR && $request->marketer_profile_id !== null) {
            $this->partners->toMarketer((int) $request->marketer_profile_id, 'seller.product_request_decision', $variables, '/product-requests', $this->once($request, $to));

            return;
        }

        $this->partners->toVendor($request->vendor_id === null ? null : (int) $request->vendor_id, 'seller.product_request_decision', $variables, '/product-requests', $this->once($request, $to));
    }

    private function changeRequest(ChangeRequest $request, string $to, ?string $reason): void
    {
        if (! in_array($to, ['approved', 'rejected'], true)) {
            return;
        }

        $this->partners->toVendor((int) $request->vendor_id, 'seller.change_request_decision', [
            'request_title' => EmailValue::t(match ($request->section) {
                ChangeRequestSection::StoreName => 'Store name',
                ChangeRequestSection::LegalDetails => 'Legal details',
                ChangeRequestSection::Address => 'Business address',
                ChangeRequestSection::BankDetails => 'Bank details',
            }),
            'decision' => EmailValue::t(ucfirst($to)),
            'note' => (string) ($reason ?? $request->rejection_reason ?? ''),
        ], '/change-requests', $this->once($request, $to));
    }

    private function adCampaign(AdCampaign $campaign, StateMoved $event): void
    {
        $to = $event->to->value;

        // Only the review decision: activation after review, or rejection (not a resume after a pause).
        if (! ($to === 'rejected' || ($to === 'active' && $event->from?->value === 'pending_review'))) {
            return;
        }

        $this->toAdvertiser((string) $campaign->advertiser_type, (int) $campaign->advertiser_id, 'ads.campaign_decision', [
            'campaign' => (string) $campaign->name,
            'decision' => EmailValue::t($to === 'active' ? 'Approved' : 'Rejected'),
            'note' => (string) ($event->reason ?? ''),
        ], $this->once($campaign, $to));
    }

    private function adBooking(AdBooking $booking, string $to, ?string $reason): void
    {
        if (! in_array($to, ['approved', 'rejected'], true)) {
            return;
        }

        $this->toAdvertiser((string) $booking->advertiser_type, (int) $booking->advertiser_id, 'ads.booking_decision', [
            'placement' => (string) ($booking->slot?->name ?? $booking->number),
            'decision' => EmailValue::t(ucfirst($to)),
            'note' => (string) ($reason ?? ''),
        ], $this->once($booking, $to));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    private function toAdvertiser(string $type, int $id, string $event, array $variables, array $options): void
    {
        if ($type === AdvertiserBilling::VENDOR) {
            $this->partners->toVendor($id, $event, $variables, '/advertising', $options);
        } else {
            $this->partners->toMarketer($id, $event, $variables, '/advertising', $options);
        }
    }

    private function couponInvitation(CouponParticipationInvitation $invitation): void
    {
        $coupon = Coupon::query()->find($invitation->coupon_id);

        if ($coupon === null) {
            return;
        }

        $discount = match ($coupon->type) {
            CouponType::Percentage => rtrim(rtrim((string) $coupon->value, '0'), '.').'%',
            CouponType::FixedAmount => EmailValue::money((string) $coupon->value, (string) $invitation->currency),
            default => EmailValue::t(ucfirst(str_replace('_', ' ', $coupon->type->value))),
        };
        $variables = ['coupon' => (string) ($invitation->title ?: $coupon->code), 'discount' => $discount, 'join_by' => EmailValue::date($invitation->closes_at, time: true)];
        $options = $this->once($invitation, 'open');
        $audience = (array) ($invitation->audience ?? []);

        if (in_array('vendor', $audience, true)) {
            Vendor::query()->where('status', Vendor::STATUS_ACTIVE)->where('type', 'product')->whereNotNull('owner_user_id')->orderBy('id')
                ->each(fn (Vendor $vendor) => $this->partners->toVendor($vendor->id, 'coupon.participation_invite', $variables, '/coupons', $options));
        }

        if (in_array('marketer', $audience, true)) {
            MarketerProfile::query()->where('global_status', MarketerStatus::Active)->whereNotNull('user_id')->orderBy('id')
                ->each(fn (MarketerProfile $marketer) => $this->partners->toMarketer($marketer->id, 'coupon.participation_invite', $variables, '/', $options));
        }
    }

    private function document(Document $document, string $to, ?string $reason): void
    {
        if (! in_array($to, ['rejected', 'expired'], true)) {
            return;
        }

        $type = DocumentType::query()->find($document->document_type_id);
        $variables = fn (string $locale): array => [
            'document' => (string) ($type === null ? $document->original_name : ($locale === 'ar' ? ($type->name_ar ?: $type->name_en) : $type->name_en)),
            'reason' => (string) ($reason ?? $document->rejection_reason ?? ''),
        ];
        $path = match ((string) $document->owner_type) {
            'marketer' => '/profile',
            'carrier' => '/company',
            default => '/documents',
        };

        $this->partners->toOwner((string) $document->owner_type, (int) $document->owner_id, 'document.'.$to, $variables,
            $document->owner_type === PartnerApplication::DOCUMENT_OWNER ? null : $path, $this->once($document, $to));
    }

    private function earningsReleased(MarketerConversion $conversion): void
    {
        $this->partners->toMarketer((int) $conversion->marketer_profile_id, 'marketer.earnings_available', [
            'amount' => EmailValue::money(bcadd((string) $conversion->net, (string) ($conversion->bonus ?? '0'), 4), (string) $conversion->currency),
            'campaign' => (string) MarketerCampaign::query()->whereKey($conversion->campaign_id)->value('title'),
        ], '/wallet', $this->once($conversion, 'released'));
    }

    private function marketerWithdrawal(MarketerWithdrawal $withdrawal, string $to, ?string $reason): void
    {
        $event = ['approved' => 'marketer.withdrawal_approved', 'processed' => 'marketer.withdrawal_paid', 'rejected' => 'marketer.withdrawal_rejected'][$to] ?? null;

        if ($event === null) {
            return;
        }

        $details = (array) ($withdrawal->payout_details ?? []);
        $this->partners->toMarketer((int) $withdrawal->marketer_profile_id, $event, [
            'withdrawal_number' => (string) $withdrawal->number,
            'amount' => EmailValue::money((string) $withdrawal->amount, (string) $withdrawal->currency),
            'reference' => (string) ($details['reference'] ?? $reason ?? $withdrawal->number),
            'reason' => (string) ($reason ?? $withdrawal->reason ?? ''),
        ], '/wallet', $this->once($withdrawal, $to));
    }

    private function deliveryWithdrawal(DeliveryWithdrawal $withdrawal, string $to, ?string $reason): void
    {
        $event = ['approved' => 'delivery.withdrawal_approved', 'paid' => 'delivery.withdrawal_paid', 'rejected' => 'delivery.withdrawal_rejected'][$to] ?? null;

        if ($event === null) {
            return;
        }

        $variables = [
            'withdrawal_number' => (string) $withdrawal->number,
            'amount' => EmailValue::money((string) $withdrawal->amount, (string) $withdrawal->currency),
            'reference' => (string) ($withdrawal->reference ?? ''),
            'reason' => (string) ($reason ?? $withdrawal->reason ?? ''),
        ];
        $options = $this->once($withdrawal, $to);

        if ($withdrawal->delivery_agent_id !== null) {
            $this->partners->toOwner('delivery_agent', (int) $withdrawal->delivery_agent_id, $event, $variables, '/earnings', $options);

            return;
        }

        $requester = $withdrawal->requested_by_user_id === null ? null : User::query()->find($withdrawal->requested_by_user_id);

        if ($requester !== null) {
            $this->emails->send($event, $requester, $variables, $options + ['panel' => 'carrier', 'url' => Panel::find('carrier')->url('/withdrawals')]);
        } elseif ($withdrawal->carrier_id !== null) {
            $this->partners->toOwner('carrier', (int) $withdrawal->carrier_id, $event, $variables, '/withdrawals', $options);
        }
    }

    /**
     * @return array{entity: Model, key: string}
     */
    private function once(Model $subject, string $status): array
    {
        return ['entity' => $subject, 'key' => $status];
    }
}
