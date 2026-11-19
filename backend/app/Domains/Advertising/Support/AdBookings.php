<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Paid ad bookings / Nawi Ads (SRS §10, §11.13, lifecycle catalogue §4.8).
 * A vendor or marketer books a slot for whole days; submitting checks the
 * slot's free capacity for every day under a row lock on the slot
 * (`max_concurrent` bookings that occupy it) and charges days × price per
 * day (store statement / marketer wallet). The admin reviews the creative;
 * a rejection, cancellation before going live or a start date passing
 * without approval refunds in full. PaidAdSchedulerJob (every 5 min)
 * schedules, starts and completes bookings.
 */
class AdBookings
{
    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private AdvertiserBilling $billing,
    ) {}

    /**
     * @param  array{starts_on: string, ends_on: string, link_url?: string|null, headline?: string|null}  $data
     */
    public function draft(AdSlot $slot, string $advertiserType, int $advertiserId, array $data, ?UploadedFile $creative, int $userId): AdBooking
    {
        if (! $slot->is_active || $slot->placement === AdSlot::PLACEMENT_TRAVEL) {
            throw new TransitionRefused(__('This placement is not on sale.'));
        }

        $start = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $end = CarbonImmutable::parse($data['ends_on'])->startOfDay();

        if ($start->lte(today()) || $end->lt($start)) {
            throw new TransitionRefused(__('Book from tomorrow onwards, ending on or after the start.'));
        }

        $days = (int) $start->diffInDays($end) + 1;
        $amount = $this->money->of((string) $slot->price_per_day, $this->countryContext->current()->currency)->multipliedBy($days);
        $booking = new AdBooking([
            'number' => 'NA-'.uniqid(),
            'ad_slot_id' => $slot->id,
            'advertiser_type' => $advertiserType,
            'advertiser_id' => $advertiserId,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'days' => $days,
            'currency' => $amount->getCurrency()->getCurrencyCode(),
            'amount' => $this->money->toStorage($amount),
            'creative_path' => $creative?->store('ads/'.$this->countryContext->current()->code, 'public'),
            'creative_type' => 'image',
            'link_url' => $data['link_url'] ?? null,
            'headline' => $data['headline'] ?? null,
        ]);
        $booking->forceFill(['status' => AdBookingStatus::Draft])->save();
        $booking->forceFill(['number' => 'NA-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT)])->save();
        $this->machine->created($booking, $advertiserType === AdvertiserBilling::VENDOR ? ActorType::Vendor : ActorType::Marketer, $userId);

        return $booking;
    }

    public function submit(AdBooking $booking, int $userId): AdBooking
    {
        if ($booking->creative_path === null) {
            throw new TransitionRefused(__('Upload the image creative first.'));
        }

        return DB::connection($booking->getConnectionName())->transaction(function () use ($booking, $userId): AdBooking {
            $slot = AdSlot::query()->lockForUpdate()->findOrFail($booking->ad_slot_id);

            if ($this->busiestDay($slot, $booking) >= $slot->max_concurrent) {
                throw new TransitionRefused(__('This placement is fully booked on some of these days.'));
            }

            $this->billing->charge($booking->advertiser_type, (int) $booking->advertiser_id, $this->money->of((string) $booking->amount, $booking->currency),
                VendorBalanceItem::TYPE_AD_BOOKING, $booking, "ad-booking:{$booking->id}:charge", "Ad booking {$booking->number}");

            return $this->machine->move($booking, AdBookingStatus::PendingReview, $booking->advertiser_type === AdvertiserBilling::VENDOR ? ActorType::Vendor : ActorType::Marketer, $userId, null, ['payment_status' => 'paid']);
        });
    }

    /**
     * ADR 11 / QC-018: sponsored video creatives are uploaded by an admin on
     * the booking (advertisers upload images only). The video replaces the
     * creative until the booking ends; the change is in the history.
     */
    public function attachVideo(AdBooking $booking, UploadedFile $video, int $adminUserId): AdBooking
    {
        if (in_array($booking->status, [AdBookingStatus::Completed, AdBookingStatus::Rejected, AdBookingStatus::Cancelled, AdBookingStatus::Expired], true)) {
            throw new TransitionRefused(__('This booking has ended.'));
        }

        $previous = $booking->creative_type === 'video' ? $booking->creative_path : null;
        $path = $video->store('ads/'.$this->countryContext->current()->code.'/video', 'public');
        $booking->forceFill(['creative_path' => $path, 'creative_type' => 'video'])->save();
        StateTransition::query()->create([
            'subject_type' => $booking->transitionSubject(),
            'subject_id' => $booking->id,
            'from_status' => $booking->status->value,
            'to_status' => $booking->status->value,
            'actor_type' => ActorType::Admin,
            'actor_user_id' => $adminUserId,
            'reason' => 'Video creative uploaded by admin',
        ]);

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return $booking;
    }

    public function approve(AdBooking $booking, int $adminUserId): AdBooking
    {
        $this->machine->move($booking, AdBookingStatus::Approved, ActorType::Admin, $adminUserId, 'Creative approved');

        return $booking;
    }

    public function reject(AdBooking $booking, int $adminUserId, string $reason): AdBooking
    {
        return $this->stopWithRefund($booking, AdBookingStatus::Rejected, ActorType::Admin, $adminUserId, $reason);
    }

    public function cancel(AdBooking $booking, ActorType $actor, int $userId): AdBooking
    {
        return $this->stopWithRefund($booking, AdBookingStatus::Cancelled, $actor, $userId, 'Cancelled');
    }

    public function move(AdBooking $booking, AdBookingStatus $to, ActorType $actor, int $userId): AdBooking
    {
        return $this->machine->move($booking, $to, $actor, $userId);
    }

    /**
     * PaidAdSchedulerJob (every 5 min).
     *
     * @return array<string, int>
     */
    public function schedule(): array
    {
        $result = ['scheduled' => 0, 'started' => 0, 'completed' => 0, 'expired' => 0];
        $today = today()->toDateString();

        AdBooking::query()->where('status', AdBookingStatus::Approved)->each(function (AdBooking $booking) use ($today, &$result): void {
            $starting = $booking->starts_on->toDateString() <= $today;
            $this->machine->move($booking, $starting ? AdBookingStatus::Active : AdBookingStatus::Scheduled, ActorType::System);
            $result[$starting ? 'started' : 'scheduled']++;
        });
        AdBooking::query()->where('status', AdBookingStatus::Scheduled)->where('starts_on', '<=', $today)->each(function (AdBooking $booking) use (&$result): void {
            $this->machine->move($booking, AdBookingStatus::Active, ActorType::System);
            $result['started']++;
        });
        AdBooking::query()->whereIn('status', [AdBookingStatus::Active, AdBookingStatus::Paused])->where('ends_on', '<', $today)->each(function (AdBooking $booking) use (&$result): void {
            $this->machine->move($booking, AdBookingStatus::Completed, ActorType::System);
            $result['completed']++;
        });
        AdBooking::query()->where('status', AdBookingStatus::PendingReview)->where('starts_on', '<=', $today)->each(function (AdBooking $booking) use (&$result): void {
            $this->stopWithRefund($booking, AdBookingStatus::Expired, ActorType::System, null, 'Start date passed without approval');
            $result['expired']++;
        });

        return $result;
    }

    /**
     * Highest number of occupying bookings on any day of this booking's range.
     */
    public function busiestDay(AdSlot $slot, AdBooking $booking): int
    {
        $others = AdBooking::query()->where('ad_slot_id', $slot->id)->whereKeyNot($booking->id)
            ->whereIn('status', AdBookingStatus::occupying())
            ->where('starts_on', '<=', $booking->ends_on)->where('ends_on', '>=', $booking->starts_on)
            ->get(['starts_on', 'ends_on']);
        $max = 0;

        for ($day = $booking->starts_on; $day->lte($booking->ends_on); $day = $day->addDay()) {
            $max = max($max, $others->filter(fn (AdBooking $other): bool => $other->starts_on->lte($day) && $other->ends_on->gte($day))->count());
        }

        return $max;
    }

    private function stopWithRefund(AdBooking $booking, AdBookingStatus $to, ActorType $actor, ?int $userId, string $reason): AdBooking
    {
        return DB::connection($booking->getConnectionName())->transaction(function () use ($booking, $to, $actor, $userId, $reason): AdBooking {
            $this->machine->move($booking, $to, $actor, $userId, $reason, $to === AdBookingStatus::Rejected ? ['rejection_reason' => mb_substr($reason, 0, 255)] : []);

            if ($booking->payment_status === 'paid') {
                $this->billing->refund($booking->advertiser_type, (int) $booking->advertiser_id, $this->money->of((string) $booking->amount, $booking->currency),
                    VendorBalanceItem::TYPE_AD_BOOKING_REFUND, $booking, "ad-booking:{$booking->id}:refund", "Ad booking {$booking->number} refund");
                $booking->forceFill(['payment_status' => 'refunded'])->save();
            }

            return $booking;
        });
    }
}
