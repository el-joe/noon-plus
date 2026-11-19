<?php

namespace App\Domains\RealEstate\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Support\ClassifiedRequests;
use App\Domains\Panels\Support\Panel;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Models\User;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Viewing and reserve requests on live classified ads. Owner decision D8:
 * a contact-details submission (name, email, country phone, preferred time,
 * message) with no payment; guests allowed. Owner decision D12: the request
 * is a record only. There is no confirm / reject / schedule / complete, no
 * status and no status notification. The advertiser is mailed once that a
 * new request arrived, sees it in a list (Seller Center → Marketplace →
 * Requests, Creator Studio → Broker → Requests, `classifieds/mine/requests`)
 * and contacts the customer outside Nawy. On a broker's ad the request also
 * opens a lead in the broker's own lead pipeline (a separate module).
 * The request types an ad takes come from its vertical
 * ({@see ClassifiedRequests}, setting `classified.request_types`).
 */
class Viewings
{
    public function __construct(private BrokerLeads $leads, private ClassifiedRequests $requests, private Emails $emails) {}

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
        $viewing = PropertyViewing::query()->create([
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

        if ($ad->marketer_profile_id !== null) {
            $this->leads->fromViewing($viewing, (int) $ad->marketer_profile_id);
        }

        $reserve = $type === 'reserve';
        // QC-028: the advertiser's email in the advertiser's language. D12: the only mail a request sends.
        $owner = User::query()->find($ad->owner_user_id);
        if ($owner !== null) {
            $panel = match (true) {
                $ad->marketer_profile_id !== null => 'marketer',
                $ad->vendor_id !== null => 'partner',
                default => null,
            };

            $this->emails->send('viewing.requested', $owner, [
                'ad_title' => (string) $ad->title,
                'kind' => EmailValue::t($reserve ? 'reserve' : 'viewing'),
                'requester' => $viewing->contact_name.' · '.$viewing->contact_email,
                'phone' => (string) $viewing->contact_phone,
                'preferred_time' => EmailValue::date($preferredAt, time: true),
                'note' => (string) $note,
            ], array_filter([
                'entity' => $viewing,
                'panel' => $panel,
                'url' => match ($panel) {
                    'marketer' => Panel::find('marketer')->url('broker/requests'),
                    'partner' => Panel::find('partner')->url('marketplace/requests'),
                    default => app(EmailLinks::class)->storefront('account/my-ads', $owner->preferredLocale()),
                },
            ]));
        }

        return $viewing;
    }

    /**
     * Requests on the ads of a classified shop (Seller Center), a broker
     * (Creator Studio) or a private owner (`classifieds/mine/requests`),
     * newest first.
     *
     * @param  array{vendor_id?: int, marketer_profile_id?: int, owner_user_id?: int}  $owner
     * @return Builder<PropertyViewing>
     */
    public function received(array $owner, ?string $type = null): Builder
    {
        return PropertyViewing::query()
            ->when(isset($owner['vendor_id']), fn (Builder $query) => $query->whereHas('ad', fn (Builder $ads) => $ads->where('vendor_id', $owner['vendor_id'])))
            ->when(isset($owner['marketer_profile_id']), fn (Builder $query) => $query->where('marketer_profile_id', $owner['marketer_profile_id']))
            ->when(isset($owner['owner_user_id']), fn (Builder $query) => $query->where('owner_user_id', $owner['owner_user_id']))
            ->when(in_array($type, ClassifiedRequests::TYPES, true), fn (Builder $query) => $query->where('type', $type))
            ->with('ad')->latest('id');
    }
}
