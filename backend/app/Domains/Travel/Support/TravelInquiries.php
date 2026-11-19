<?php

namespace App\Domains\Travel\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\Panel;
use App\Domains\Travel\Enums\InquiryStatus;
use App\Domains\Travel\Models\TravelInquiry;
use App\Domains\Travel\Models\TravelPackage;
use App\Models\User;
use App\Shared\Localization\InLocale;
use App\Shared\Notifications\Email\Emails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;

/**
 * Travel inquiries (SRS §12.2): new → contacted → converted | closed.
 */
class TravelInquiries
{
    public function __construct(private StateMachine $machine, private Emails $emails) {}

    /**
     * @param  array{name: string, email: string, phone: string, message: string}  $data
     */
    public function create(TravelPackage $package, ?int $customerUserId, array $data): TravelInquiry
    {
        if (! $package->status->isBookable()) {
            throw new TransitionRefused(__('This trip is not on sale.'));
        }

        $data['phone'] = CountryPhone::normalize((string) $data['phone']) ?? throw new TransitionRefused(__('Enter a valid phone number.'));
        $inquiry = new TravelInquiry($data + ['travel_package_id' => $package->id, 'vendor_id' => $package->vendor_id, 'customer_user_id' => $customerUserId]);
        $inquiry->forceFill(['status' => InquiryStatus::New])->save();
        $this->machine->created($inquiry, $customerUserId === null ? ActorType::System : ActorType::Customer, $customerUserId);

        $owner = User::query()->find($package->vendor->owner_user_id);

        if ($owner !== null) {
            $this->emails->send('travel.inquiry_received', $owner, [
                'trip' => InLocale::run($owner->preferredLocale(), fn (): string => $package->localizedTitle()),
                'from' => $inquiry->name.' · '.$inquiry->email.' · '.$inquiry->phone,
                'question' => (string) $inquiry->message,
            ], ['entity' => $inquiry, 'panel' => 'travel_agency', 'url' => Panel::find('travel_agency')->url('inquiries')]);
        }

        $this->emails->send('travel.inquiry_sent', $inquiry->email, [
            'trip' => $package->localizedTitle(),
            'agency' => (string) $package->vendor->store_name,
        ], ['entity' => $inquiry, 'locale' => app()->getLocale(), 'name' => $inquiry->name]);

        return $inquiry;
    }

    public function move(TravelInquiry $inquiry, InquiryStatus $to, int $userId, ?int $bookingId = null): TravelInquiry
    {
        return $this->machine->move($inquiry, $to, ActorType::Agency, $userId, null, $bookingId === null ? [] : ['travel_booking_id' => $bookingId]);
    }
}
