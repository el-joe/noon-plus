<?php

namespace App\Domains\Travel\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelBookingModification;
use App\Domains\Travel\Models\TravelDeparture;
use App\Models\User;
use App\Shared\Http\StorefrontLinks;
use App\Shared\Localization\InLocale;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\RequestNotification;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Brick\Money\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Travel booking requests (SRS §12.2; owner decision WP6: no payment on
 * Nawy — the agency settles with the customer).
 *
 * A request takes its seats at once under the departure row lock
 * ({@see SeatAllocator}). Submitted straight away it is `requested`;
 * started as a draft it is `held` for `travel.seat_hold_minutes` until the
 * customer submits it (unsubmitted holds expire every minute and give the
 * seats back). Guests and signed-in customers leave name, email and a
 * phone of the country (signed-in details are prefilled but editable);
 * traveller names are optional. On submission the agency is mailed and the
 * customer gets a confirmation. The agency confirms (confirmation number,
 * customer mailed) or rejects with a reason (seats released); the customer
 * or the agency can cancel before the trip; confirmed trips complete
 * nightly (03:45) after the return date.
 */
class TravelBookings
{
    public function __construct(
        private StateMachine $machine,
        private SeatAllocator $seats,
        private TravelPackages $packages,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private VerticalSettings $settings,
    ) {}

    /**
     * @param  array<int, array{room_type: string, travellers: int}>  $rooms
     * @param  array{name: string, email: string, phone: string, message?: string|null}  $contact
     * @param  array<int, string>  $travellerNames
     */
    public function request(TravelDeparture $departure, ?User $customer, array $rooms, array $contact, array $travellerNames = [], bool $submit = true, ?string $referrer = null): TravelBooking
    {
        $package = $departure->package;

        if (! $package->status->isBookable()) {
            throw new TransitionRefused(__('This trip is not open for booking.'));
        }

        $this->packages->assertAgency($package->vendor);
        $phone = CountryPhone::normalize((string) ($contact['phone'] ?? '')) ?? throw new TransitionRefused(__('Enter a valid phone number.'));
        [$lines, $seats, $total] = $this->price($departure, $rooms);

        if (! $submit && $customer === null && $seats > (int) config('nawy.travel.guest_hold_max_seats', 4)) {
            throw new TransitionRefused(__('Sign in to hold more than :count seats, or send the booking request now.', ['count' => (int) config('nawy.travel.guest_hold_max_seats', 4)]));
        }

        if (count(array_filter($travellerNames)) > $seats) {
            throw new TransitionRefused(__('There are more traveller names than seats.'));
        }

        $marketer = blank($referrer) ? null : MarketerProfile::query()->where('slug', $referrer)->where('global_status', MarketerStatus::Active)->first();

        $booking = DB::connection($departure->getConnectionName())->transaction(function () use ($departure, $package, $customer, $lines, $seats, $total, $contact, $phone, $travellerNames, $submit, $marketer, $referrer): TravelBooking {
            $this->seats->take($departure->id, $seats);
            $booking = new TravelBooking([
                'number' => 'TB-'.uniqid(),
                'customer_user_id' => $customer?->id,
                'contact_name' => mb_substr(trim($contact['name']), 0, 120),
                'contact_email' => mb_strtolower(trim($contact['email'])),
                'contact_phone' => $phone,
                'message' => isset($contact['message']) ? mb_substr((string) $contact['message'], 0, 1000) : null,
                'access_token' => Str::random(40),
                'vendor_id' => $package->vendor_id,
                'travel_package_id' => $package->id,
                'travel_departure_id' => $departure->id,
                'rooms' => $lines,
                'seats' => $seats,
                'currency' => $total->getCurrency()->getCurrencyCode(),
                'total' => $this->money->toStorage($total),
                'hold_expires_at' => $submit ? null : now()->addMinutes($this->settings->int('travel.seat_hold_minutes')),
                'marketer_profile_id' => $marketer?->id,
                'referral_code' => $marketer === null ? null : mb_substr((string) $referrer, 0, 16),
                'locale' => app()->getLocale(),
            ]);
            $booking->forceFill(['status' => $submit ? BookingStatus::Requested : BookingStatus::Held, 'requested_at' => $submit ? now() : null])->save();
            $booking->forceFill(['number' => 'TB-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $booking->id, 7, '0', STR_PAD_LEFT)])->save();

            foreach (array_values(array_filter(array_map('trim', $travellerNames))) as $name) {
                $booking->travellers()->create(['full_name' => mb_substr($name, 0, 160)]);
            }

            $this->machine->created($booking, $customer === null ? ActorType::System : ActorType::Customer, $customer?->id, "{$seats} seat(s)");

            return $booking;
        });

        $this->packages->syncSoldOut($package);

        if ($submit) {
            $this->notifySubmitted($booking->refresh());
        }

        return $booking->refresh();
    }

    /**
     * Submit a held request before its hold ends.
     */
    public function submit(TravelBooking $booking, ?int $userId): TravelBooking
    {
        if ($booking->status !== BookingStatus::Held || $booking->hold_expires_at === null || $booking->hold_expires_at->isPast()) {
            throw new TransitionRefused(__('The seat hold has ended. Start a new booking.'));
        }

        $this->machine->move($booking, BookingStatus::Requested, ActorType::Customer, $userId, 'Submitted', ['hold_expires_at' => null, 'requested_at' => now()]);
        $this->notifySubmitted($booking->refresh());

        return $booking;
    }

    public function confirm(TravelBooking $booking, int $userId): TravelBooking
    {
        $this->machine->move($booking, BookingStatus::Confirmed, ActorType::Agency, $userId, null, [
            'confirmed_at' => now(),
            'confirmation_number' => 'TV-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $booking->id, 7, '0', STR_PAD_LEFT),
        ]);
        $this->notifyCustomer($booking->refresh(), 'travel_booking.confirmed', fn (): array => [__('Your trip booking :number is confirmed', ['number' => $booking->number]), [
            __(':agency confirmed your booking for :trip on :date.', ['agency' => $booking->vendor->store_name, 'trip' => $booking->package->localizedTitle(), 'date' => $booking->departure->departs_on->toDateString()]),
            __('The agency will contact you to arrange payment directly.'),
        ]]);

        return $booking;
    }

    public function reject(TravelBooking $booking, int $userId, string $reason): TravelBooking
    {
        return $this->release($booking, BookingStatus::Rejected, ActorType::Agency, $userId, $reason, 'travel_booking.rejected', 'Your trip booking :number could not be confirmed');
    }

    public function cancel(TravelBooking $booking, ActorType $actor, ?int $userId, string $reason): TravelBooking
    {
        if ($booking->departure->departs_on->isPast()) {
            throw new TransitionRefused(__('The trip has already left.'));
        }

        return $this->release($booking, BookingStatus::Cancelled, $actor, $userId, $reason, 'travel_booking.cancelled', 'Your trip booking :number is cancelled');
    }

    /**
     * Seat-hold expiry (every minute): unsubmitted holds past their time give the seats back.
     */
    public function expireHolds(): int
    {
        $count = 0;

        TravelBooking::query()->where('status', BookingStatus::Held)->where('hold_expires_at', '<=', now())->orderBy('id')
            ->each(function (TravelBooking $booking) use (&$count): void {
                DB::connection($booking->getConnectionName())->transaction(function () use ($booking): void {
                    $this->seats->release($booking->travel_departure_id, $booking->seats);
                    $this->machine->move($booking, BookingStatus::HoldExpired, ActorType::System, null, 'Seat hold expired');
                });
                $this->packages->syncSoldOut($booking->package);
                $count++;
            });

        return $count;
    }

    /**
     * CompleteTravelBookingsJob (03:45): confirmed trips whose return date passed complete.
     */
    public function completeDue(): int
    {
        $count = 0;

        TravelBooking::query()->where('status', BookingStatus::Confirmed)
            ->whereHas('departure', fn ($query) => $query->where('returns_on', '<', now()->toDateString()))
            ->orderBy('id')
            ->each(function (TravelBooking $booking) use (&$count): void {
                $this->machine->move($booking, BookingStatus::Completed, ActorType::System, null, 'Trip ended', ['completed_at' => now()]);
                $count++;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function requestModification(TravelBooking $booking, string $type, array $details, ?int $userId): TravelBookingModification
    {
        if (! in_array($booking->status, [BookingStatus::Requested, BookingStatus::Confirmed], true)) {
            throw new TransitionRefused(__('Changes can be requested on submitted bookings.'));
        }

        if (! in_array($type, ['change_departure', 'name_correction', 'special_request', 'other'], true)) {
            throw new TransitionRefused(__('Choose what to change.'));
        }

        return $booking->modifications()->create(['type' => $type, 'details' => $details, 'status' => 'requested', 'requested_by_user_id' => $userId]);
    }

    /**
     * The agency decides. Approving a departure change moves the seats to
     * the new departure of the same package under both row locks (the
     * quoted total is not recalculated; the agency settles any difference).
     */
    public function decideModification(TravelBookingModification $modification, bool $approve, int $userId, ?string $note = null): TravelBookingModification
    {
        if ($modification->status !== 'requested') {
            throw new TransitionRefused(__('This request was already answered.'));
        }

        return DB::connection($modification->getConnectionName())->transaction(function () use ($modification, $approve, $userId, $note): TravelBookingModification {
            $booking = $modification->booking;

            if ($approve && $modification->type === 'change_departure') {
                $target = TravelDeparture::query()->where('travel_package_id', $booking->travel_package_id)->findOrFail((int) ($modification->details['departure_id'] ?? 0));
                TravelDeparture::query()->whereKey([$booking->travel_departure_id, $target->id])->orderBy('id')->lockForUpdate()->get();
                $this->seats->take($target->id, $booking->seats);
                $this->seats->release($booking->travel_departure_id, $booking->seats);
                $booking->forceFill(['travel_departure_id' => $target->id])->save();
            }

            $modification->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decision_note' => $note, 'decided_by_user_id' => $userId])->save();

            return $modification;
        });
    }

    /**
     * @param  array<int, array{room_type: string, travellers: int}>  $rooms
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: Money}
     */
    private function price(TravelDeparture $departure, array $rooms): array
    {
        $prices = $departure->rooms()->pluck('price_per_person', 'room_type');
        $total = $this->money->zero($this->countryContext->current()->currency);
        $lines = [];
        $seats = 0;

        foreach ($rooms as $room) {
            $type = (string) ($room['room_type'] ?? '');
            $travellers = (int) ($room['travellers'] ?? 0);

            if (! $prices->has($type) || $travellers < 1) {
                throw new TransitionRefused(__('Choose an available room type and the number of travellers.'));
            }

            $unit = $this->money->of((string) $prices->get($type), $total->getCurrency()->getCurrencyCode());
            $lineTotal = $unit->multipliedBy($travellers);
            $lines[] = ['room_type' => $type, 'travellers' => $travellers, 'unit_price' => (string) $unit->getAmount(), 'line_total' => (string) $lineTotal->getAmount()];
            $total = $total->plus($lineTotal);
            $seats += $travellers;
        }

        if ($seats < 1 || $seats > 20) {
            throw new TransitionRefused(__('A booking has between 1 and 20 travellers.'));
        }

        return [$lines, $seats, $total];
    }

    private function release(TravelBooking $booking, BookingStatus $to, ActorType $actor, ?int $userId, string $reason, string $kind, string $subjectKey): TravelBooking
    {
        if (! in_array($to, $booking->status->allowedNext(), true)) {
            throw new TransitionRefused(__('This booking cannot be :action.', ['action' => $to->value]));
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new TransitionRefused(__('Give a reason.'));
        }

        DB::connection($booking->getConnectionName())->transaction(function () use ($booking, $to, $actor, $userId, $reason): void {
            $this->seats->release($booking->travel_departure_id, $booking->seats);
            $this->machine->move($booking, $to, $actor, $userId, $reason, ['cancelled_at' => now(), 'decision_reason' => mb_substr($reason, 0, 255), 'hold_expires_at' => null]);
        });

        $this->packages->syncSoldOut($booking->package);

        if ($booking->requested_at !== null) {
            $this->notifyCustomer($booking->refresh(), $kind, fn (): array => [__($subjectKey, ['number' => $booking->number]), [__('Reason: :reason', ['reason' => $reason])]]);
        }

        return $booking->refresh();
    }

    private function notifySubmitted(TravelBooking $booking): void
    {
        $booking->loadMissing(['package', 'departure', 'vendor']);
        $summary = fn (): string => __(':trip · departs :date · :seats seat(s) · quoted :total :currency', ['trip' => $booking->package->localizedTitle(), 'date' => $booking->departure->departs_on->toDateString(), 'seats' => $booking->seats, 'total' => $this->money->of((string) $booking->total, $booking->currency)->getAmount(), 'currency' => $booking->currency]);
        $owner = User::query()->find($booking->vendor->owner_user_id);

        // QC-028: each email in its recipient's language.
        $owner?->notify(InLocale::run($owner->preferredLocale(), fn (): RequestNotification => new RequestNotification('travel_booking.requested', __('New booking request :number', ['number' => $booking->number]), [
            $summary(),
            __('Customer: :name · :email · :phone', ['name' => $booking->contact_name, 'email' => $booking->contact_email, 'phone' => $booking->contact_phone]),
            __('Confirm or reject it in Travel Partner → Bookings, and settle payment with the customer directly.'),
        ], $booking->number)));

        $this->notifyCustomer($booking, 'travel_booking.received', fn (): array => [__('We received your booking request :number', ['number' => $booking->number]), [
            $summary(),
            __(':agency will confirm your seats and contact you about payment. No payment is taken on Nawy.', ['agency' => $booking->vendor->store_name]),
        ]]);
    }

    /**
     * D4 customer emails, written in the language the customer booked in
     * (QC-028) — also when the agency acts from its panel in another one.
     *
     * @param  Closure(): array{0: string, 1: array<int, string>}  $content
     */
    private function notifyCustomer(TravelBooking $booking, string $kind, Closure $content): void
    {
        $notification = InLocale::run($booking->locale ?? config('app.locale'), function () use ($booking, $kind, $content): RequestNotification {
            [$subject, $lines] = $content();

            return new RequestNotification($kind, $subject, $lines, $booking->number, $this->statusLink($booking), __('View your booking'));
        });

        Notification::route('mail', $booking->contact_email)->notify($notification->locale($booking->locale ?? config('app.locale')));
    }

    /**
     * The booking status page on the website with the booking's access
     * token (G-W20): `{storefront}/{market}/travel/bookings/{number}?token=…`.
     */
    public function statusLink(TravelBooking $booking): string
    {
        return app(StorefrontLinks::class)->to('travel/bookings/'.$booking->number, ['token' => $booking->access_token]);
    }

    /**
     * Mail the status links of the bookings made with this email (G-W20).
     * Says nothing back about whether any exist; at most the 10 newest.
     */
    public function resendLinks(string $email): void
    {
        $bookings = TravelBooking::query()->where('contact_email', mb_strtolower(trim($email)))
            ->whereNotIn('status', [BookingStatus::HoldExpired])->with('package')->latest('id')->limit(10)->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $lines = [__('Here are the links to your trip booking requests:')];

        foreach ($bookings as $booking) {
            $lines[] = __(':number · :trip · :status: :link', ['number' => $booking->number, 'trip' => $booking->package->title_en, 'status' => str_replace('_', ' ', $booking->status->value), 'link' => $this->statusLink($booking)]);
        }

        Notification::route('mail', $bookings->first()->contact_email)->notify(new RequestNotification(
            'travel_booking.links', __('Your Nawy trip bookings'), $lines, null,
            $bookings->count() === 1 ? $this->statusLink($bookings->first()) : null, __('View your booking'),
        ));
    }
}
