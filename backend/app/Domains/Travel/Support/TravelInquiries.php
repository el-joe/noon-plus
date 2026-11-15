<?php

namespace App\Domains\Travel\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Travel\Enums\InquiryStatus;
use App\Domains\Travel\Models\TravelInquiry;
use App\Domains\Travel\Models\TravelPackage;
use App\Models\User;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Illuminate\Support\Facades\Notification;

/**
 * Travel inquiries (SRS §12.2): new → contacted → converted | closed.
 */
class TravelInquiries
{
    public function __construct(private StateMachine $machine) {}

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

        User::query()->find($package->vendor->owner_user_id)?->notify(new RequestNotification('travel_inquiry.received', __('New inquiry about :trip', ['trip' => $package->title_en]), [
            __('From :name · :email · :phone', ['name' => $inquiry->name, 'email' => $inquiry->email, 'phone' => $inquiry->phone]),
            $inquiry->message,
        ]));
        Notification::route('mail', $inquiry->email)->notify(new RequestNotification('travel_inquiry.sent', __('Your question about :trip was sent', ['trip' => $package->title_en]), [__(':agency will reply to you by email or phone.', ['agency' => $package->vendor->store_name])]));

        return $inquiry;
    }

    public function move(TravelInquiry $inquiry, InquiryStatus $to, int $userId, ?int $bookingId = null): TravelInquiry
    {
        return $this->machine->move($inquiry, $to, ActorType::Vendor, $userId, null, $bookingId === null ? [] : ['travel_booking_id' => $bookingId]);
    }
}
