<?php

namespace App\Domains\RealEstate\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Support\ClassifiedRequests;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Domains\RealEstate\Enums\ViewingStatus;
use App\Domains\RealEstate\Models\BrokerLead;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Models\User;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Viewing and reserve requests on live classified ads (owner decision D8:
 * a contact-details submission — name, email, country phone, preferred
 * time — with no payment; guests allowed). The request types an ad takes
 * come from its vertical ({@see ClassifiedRequests}, setting
 * `classified.request_types`). The advertiser is mailed, the customer gets
 * a confirmation; on a broker's ad the request opens a lead and confirming
 * it moves that lead to `viewing_scheduled`.
 */
class Viewings
{
    public function __construct(private StateMachine $machine, private BrokerLeads $leads, private ClassifiedRequests $requests) {}

    /**
     * @param  array{name: string, email: string, phone: string}  $contact
     */
    public function request(ClassifiedAd $ad, ?int $customerUserId, array $contact, CarbonInterface $preferredAt, ?string $note = null, string $type = 'viewing'): PropertyViewing
    {
        if ($ad->status !== ClassifiedAdStatus::Active) {
            throw new TransitionRefused(__('Requests can be sent on live ads only.'));
        }

        if (! $this->requests->allows($ad, $type)) {
            throw new TransitionRefused($type === 'reserve' ? __('This ad does not take reserve requests.') : __('This ad does not take viewing requests.'));
        }

        if ($preferredAt->isPast()) {
            throw new TransitionRefused(__('Choose a time in the future.'));
        }

        $phone = CountryPhone::normalize((string) $contact['phone']) ?? throw new TransitionRefused(__('Enter a valid phone number.'));
        $viewing = new PropertyViewing([
            'classified_ad_id' => $ad->id,
            'type' => $type,
            'customer_user_id' => $customerUserId,
            'contact_name' => mb_substr(trim($contact['name']), 0, 120),
            'contact_email' => mb_strtolower(trim($contact['email'])),
            'contact_phone' => $phone,
            'marketer_profile_id' => $ad->marketer_profile_id,
            'owner_user_id' => $ad->owner_user_id,
            'preferred_at' => $preferredAt,
            'note' => $note,
        ]);
        $viewing->forceFill(['status' => ViewingStatus::Requested])->save();
        $this->machine->created($viewing, $customerUserId === null ? ActorType::System : ActorType::Customer, $customerUserId);

        if ($ad->marketer_profile_id !== null) {
            $this->leads->fromViewing($viewing, (int) $ad->marketer_profile_id);
        }

        $reserve = $type === 'reserve';
        User::query()->find($ad->owner_user_id)?->notify(new RequestNotification($reserve ? 'reserve.requested' : 'viewing.requested', $reserve ? __('Reserve request for :title', ['title' => $ad->title]) : __('Viewing request for :title', ['title' => $ad->title]), [
            $reserve
                ? __(':name would like you to hold it until :at.', ['name' => $viewing->contact_name, 'at' => $preferredAt->toDayDateTimeString()])
                : __(':name would like to view it on :at.', ['name' => $viewing->contact_name, 'at' => $preferredAt->toDayDateTimeString()]),
            __('Contact: :email · :phone', ['email' => $viewing->contact_email, 'phone' => $viewing->contact_phone]),
            (string) $note,
        ], $ad->number));
        Notification::route('mail', $viewing->contact_email)->notify(new RequestNotification($reserve ? 'reserve.received' : 'viewing.received', $reserve ? __('Your reserve request for :title was sent', ['title' => $ad->title]) : __('Your viewing request for :title was sent', ['title' => $ad->title]), [
            __('The advertiser will contact you to confirm the time. No payment is taken on Nawy.'),
        ], $ad->number));

        return $viewing;
    }

    public function confirm(PropertyViewing $viewing, int $userId, ?CarbonInterface $scheduledAt = null): PropertyViewing
    {
        $this->machine->move($viewing, ViewingStatus::Confirmed, ActorType::Marketer, $userId, null, ['scheduled_at' => $scheduledAt ?? $viewing->preferred_at]);
        $lead = $viewing->marketer_profile_id === null ? null : BrokerLead::query()->where(['marketer_profile_id' => $viewing->marketer_profile_id, 'source' => 'viewing', 'source_id' => $viewing->id])->first();

        if ($lead !== null && in_array(LeadStatus::ViewingScheduled, $lead->status->allowedNext(), true)) {
            $this->leads->move($lead, LeadStatus::ViewingScheduled, $userId);
        }

        return $viewing;
    }

    public function move(PropertyViewing $viewing, ViewingStatus $to, ActorType $actor, int $userId): PropertyViewing
    {
        return $this->machine->move($viewing, $to, $actor, $userId);
    }
}
