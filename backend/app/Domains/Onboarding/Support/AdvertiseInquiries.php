<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Onboarding\Enums\AdvertiseInquiryStatus;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Notifications\RequestNotification;
use App\Shared\StateMachine\StateMachine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Advertise requests (PORTAL_PAGES §4 "Request / contact form"): the
 * portal form saves one per country and confirms by email; the ads team
 * works the queue in admin (new → contacted → converted | closed).
 */
class AdvertiseInquiries
{
    public function __construct(private StateMachine $machine) {}

    /**
     * @param  array{name: string, email: string, phone: string, company?: string|null, advertiser_type: string, interest: string, monthly_budget?: string|null, message?: string|null}  $data
     */
    public function create(array $data, string $locale, ?int $customerUserId = null): AdvertiseInquiry
    {
        $inquiry = AdvertiseInquiry::query()->create([
            'number' => 'ADV-'.Str::upper(Str::random(8)),
            'status' => AdvertiseInquiryStatus::New,
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'phone' => $data['phone'],
            'company' => $data['company'] ?? null,
            'advertiser_type' => $data['advertiser_type'],
            'interest' => $data['interest'],
            'monthly_budget' => $data['monthly_budget'] ?? null,
            'message' => $data['message'] ?? null,
            'locale' => in_array($locale, ['en', 'ar'], true) ? $locale : 'en',
            'customer_user_id' => $customerUserId,
        ]);
        $this->machine->created($inquiry, ActorType::Customer, $customerUserId);

        Notification::route('mail', $inquiry->email)->notify(new RequestNotification(
            'advertise_inquiry.received',
            __('We received your advertising request'),
            [__('Thank you, :name. A Nawy Ads specialist will reply within 2 business days.', ['name' => $inquiry->name])],
            $inquiry->number,
        ));

        if (filled($opsEmail = config('nawy.ops_alert_email'))) {
            Notification::route('mail', $opsEmail)->notify(new RequestNotification(
                'advertise_inquiry.new',
                __('New advertising request'),
                [$inquiry->name.' · '.$inquiry->email.' · '.$inquiry->advertiser_type.' · '.$inquiry->interest],
                $inquiry->number,
            ));
        }

        return $inquiry;
    }

    public function move(AdvertiseInquiry $inquiry, AdvertiseInquiryStatus $to, int $adminUserId, ?string $note = null): AdvertiseInquiry
    {
        return $this->machine->move($inquiry, $to, ActorType::Admin, $adminUserId, $note, array_filter([
            'admin_note' => $note === null ? null : mb_substr($note, 0, 1000),
            'assigned_to_user_id' => $inquiry->assigned_to_user_id ?? $adminUserId,
        ], fn ($value): bool => $value !== null));
    }
}
