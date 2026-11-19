<?php

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Payments\Models\Payment;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Models\TravelInquiry;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Notification::fake();
    $this->agency = Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays']);
});

/**
 * An approved package of the test agency with one departure in 30 days.
 *
 * @param  array<string, string>  $rooms
 * @return array{0: TravelPackage, 1: TravelDeparture}
 */
function activeTrip(int $capacity = 4, array $rooms = ['double' => '2000.00', 'single' => '2600.00'], ?array $policy = null): array
{
    $packages = app(TravelPackages::class);
    $package = $packages->create(test()->agency, [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة ٧ ليال', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Flights', 'Hotel'],
        'cancellation_policy' => $policy ?? [['days_before' => 21, 'refund_percent' => 100], ['days_before' => 7, 'refund_percent' => 50]],
    ], 1);
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), $capacity, $rooms);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    return [$package->refresh(), $departure->refresh()];
}

/**
 * A submitted booking request (guest unless a customer is given).
 */
function bookingRequest(TravelDeparture $departure, int $travellers = 2, ?User $customer = null, bool $submit = true): TravelBooking
{
    return app(TravelBookings::class)->request($departure, $customer, [['room_type' => 'double', 'travellers' => $travellers]], [
        'name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'phone' => '0501234567',
    ], submit: $submit);
}

describe('packages', function () {
    it('needs departures, itinerary, inclusions and the contract file before review, and an approval to go on sale', function () {
        $packages = app(TravelPackages::class);
        $package = $packages->create($this->agency, ['title_en' => 'Baku 4 nights', 'title_ar' => 'باكو', 'destination_city' => 'Baku', 'nights' => 4], 1);

        expect(fn () => $packages->submit($package, 1))->toThrow(TransitionRefused::class, 'future departure');
        $packages->addDeparture($package, now()->addDays(20)->toDateString(), now()->addDays(24)->toDateString(), 10, ['double' => '900']);
        expect(fn () => $packages->submit($package->refresh(), 1))->toThrow(TransitionRefused::class, 'itinerary');
        $packages->update($package, ['itinerary' => [['day' => 1, 'title' => 'Old city']], 'inclusions' => ['Hotel']]);
        expect(fn () => $packages->submit($package->refresh(), 1))->toThrow(TransitionRefused::class, 'contract file');
        $packages->attachContract($package, UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'));
        $packages->submit($package->refresh(), 1);

        $this->getJson("/api/v1/uae-en/travel/packages/{$package->slug}")->assertNotFound();
        $packages->approve($package->refresh(), 1);
        $this->getJson("/api/v1/uae-en/travel/packages/{$package->slug}")->assertOk()
            ->assertJsonPath('data.departures.0.seats_left', 10)->assertJsonPath('data.departures.0.rooms.0.price_per_person.amount', '900.00');
    });

    it('refuses a suspended agency and a travel agreement that is published but unsigned', function () {
        $packages = app(TravelPackages::class);
        expect(fn () => $packages->create(Vendor::factory()->create(['type' => 'product']), ['title_en' => 'X trip', 'title_ar' => 'x', 'destination_city' => 'X', 'nights' => 1], 1))
            ->toThrow(TransitionRefused::class, 'not active');

        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::TravelAgency, 'name' => 'Agency agreement']);
        app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Agreement']), 1);

        expect(fn () => activeTrip())->toThrow(TransitionRefused::class, 'travel agency agreement');
    });
});

describe('seat capacity', function () {
    it('never takes more seats than the departure has, whatever copy of the departure the caller holds', function () {
        [$package, $departure] = activeTrip(capacity: 3);
        $stale = TravelDeparture::query()->findOrFail($departure->id);

        bookingRequest($departure, 2);
        expect(fn () => bookingRequest($stale, 2))->toThrow(TransitionRefused::class, 'Only 1 seat(s) left');
        bookingRequest($stale, 1);

        expect($departure->refresh()->seats_reserved)->toBe(3)
            ->and($package->refresh()->status)->toBe(PackageStatus::SoldOut)
            ->and((int) TravelBooking::query()->sum('seats'))->toBe(3);
        expect(fn () => bookingRequest($stale, 1))->toThrow(TransitionRefused::class);
    });

    it('releases an unsubmitted 15-minute hold and puts the package back on sale', function () {
        [$package, $departure] = activeTrip(capacity: 2);

        $response = $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", [
            'rooms' => [['room_type' => 'double', 'travellers' => 2]], 'name' => 'Guest Omar', 'email' => 'omar@example.com', 'phone' => '0559876543', 'submit' => false,
        ])->assertCreated()->assertJsonPath('data.status', 'held')->assertJsonPath('data.quoted_total.amount', '4000.00')->assertJsonPath('data.payment', 'with_agency');
        [$number, $token] = [$response->json('data.number'), $response->json('data.access_token')];
        expect($package->refresh()->status)->toBe(PackageStatus::SoldOut);
        Notification::assertNothingSent();

        $this->travel(14)->minutes();
        $this->artisan('nawy:travel:expire-holds')->assertSuccessful();
        expect(TravelBooking::query()->sole()->status)->toBe(BookingStatus::Held);

        $this->travel(2)->minutes();
        $this->artisan('nawy:travel:expire-holds')->assertSuccessful();

        expect(TravelBooking::query()->sole()->status)->toBe(BookingStatus::HoldExpired)
            ->and($departure->refresh()->seats_reserved)->toBe(0)
            ->and($package->refresh()->status)->toBe(PackageStatus::Active);
        $this->postJson("/api/v1/uae-en/travel/bookings/{$number}/submit", [], ['X-Booking-Token' => $token])->assertStatus(422);
    });

    it('submits a held request before the hold ends and then notifies', function () {
        [, $departure] = activeTrip();
        $held = bookingRequest($departure, submit: false);

        $this->postJson("/api/v1/uae-en/travel/bookings/{$held->number}/submit", [], ['X-Booking-Token' => $held->access_token])->assertOk()->assertJsonPath('data.status', 'requested');
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'travel.booking_received');
    });
});

describe('booking requests (owner decision: no payment)', function () {
    it('lets a guest request seats with contact details, mails the agency and the customer, and never creates a payment', function () {
        [, $departure] = activeTrip();
        $owner = User::factory()->create();
        $this->agency->update(['owner_user_id' => $owner->id]);
        $body = ['rooms' => [['room_type' => 'double', 'travellers' => 2]], 'traveller_names' => ['Sara Ahmed', ''], 'message' => 'Ground floor please'];

        $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", $body + ['name' => 'Sara Ahmed'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone'], 'error.fields');
        $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", $body + ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'phone' => '+966 50 123 4567'])->assertUnprocessable()->assertJsonValidationErrors('phone', 'error.fields');
        $response = $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", $body + ['name' => 'Sara Ahmed', 'email' => 'Sara@Example.com', 'phone' => '050-123 4567'])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.contact.phone', '+971501234567');
        [$number, $token] = [$response->json('data.number'), $response->json('data.access_token')];

        $booking = TravelBooking::query()->sole();
        expect($booking)->customer_user_id->toBeNull()->contact_email->toBe('sara@example.com')->message->toBe('Ground floor please')
            ->and($booking->travellers()->pluck('full_name')->all())->toBe(['Sara Ahmed'])
            ->and($departure->refresh()->seats_reserved)->toBe(2)
            ->and(Payment::query()->count())->toBe(0)
            ->and(LedgerTransaction::query()->count())->toBe(0);
        Notification::assertSentTo($owner, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'travel.booking_request' && $mail->variables['booking_number'] === $number);
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail, array $channels, object $notifiable): bool => $mail->event === 'travel.booking_received' && array_key_first((array) $notifiable->routes['mail']) === 'sara@example.com');

        $this->getJson("/api/v1/uae-en/travel/bookings/{$number}")->assertNotFound();
        $this->getJson("/api/v1/uae-en/travel/bookings/{$number}", ['X-Booking-Token' => 'wrong'])->assertNotFound();
        $this->getJson("/api/v1/uae-en/travel/bookings/{$number}", ['X-Booking-Token' => $token])->assertOk()->assertJsonPath('data.travellers', ['Sara Ahmed']);
    });

    it('prefills a signed-in customer and lets them edit, and keeps requests private', function () {
        [, $departure] = activeTrip();
        [$customer, $token] = customerWithToken();
        $customer->update(['phone' => '0551112222']);

        $number = $this->withToken($token)->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", ['rooms' => [['room_type' => 'single', 'travellers' => 1]], 'name' => 'Sara (work)'])
            ->assertCreated()->assertJsonPath('data.contact.name', 'Sara (work)')->assertJsonPath('data.contact.email', $customer->email)->assertJsonPath('data.contact.phone', '+971551112222')->json('data.number');
        $this->withToken($token)->getJson('/api/v1/uae-en/travel/bookings')->assertOk()->assertJsonPath('data.0.number', $number);

        app('auth')->forgetGuards();
        [, $strangerToken] = customerWithToken();
        $this->withToken($strangerToken)->getJson("/api/v1/uae-en/travel/bookings/{$number}")->assertNotFound();
        $this->withToken($strangerToken)->postJson("/api/v1/uae-en/travel/bookings/{$number}/cancel", ['reason' => 'not mine'])->assertNotFound();
        expect($this->withoutToken()->getJson('/api/v1/omn-en/travel/packages')->json('meta.total'))->toBe(0);
    });

    it('is confirmed by the agency and completes after the return date; rejection frees the seats and tells the customer', function () {
        [$package, $departure] = activeTrip(capacity: 4);
        $first = bookingRequest($departure, 2);
        $second = bookingRequest($departure, 2);
        expect($package->refresh()->status)->toBe(PackageStatus::SoldOut);

        app(TravelBookings::class)->confirm($first, 1);
        expect($first->refresh())->status->toBe(BookingStatus::Confirmed)->confirmation_number->toStartWith('TV-AE-');
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'travel.booking_confirmed');

        expect(fn () => app(TravelBookings::class)->reject($second, 1, 'x'))->toThrow(TransitionRefused::class);
        app(TravelBookings::class)->reject($second, 1, 'Hotel is full that week');
        expect($second->refresh())->status->toBe(BookingStatus::Rejected)->decision_reason->toBe('Hotel is full that week')
            ->and($departure->refresh()->seats_reserved)->toBe(2)
            ->and($package->refresh()->status)->toBe(PackageStatus::Active);
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'travel.booking_rejected');

        $this->travel(38)->days();
        $this->artisan('nawy:travel:complete-bookings')->assertSuccessful();
        expect($first->refresh()->status)->toBe(BookingStatus::Completed);
    });

    it('lets the customer cancel with the token before departure, giving the seats back', function () {
        [, $departure] = activeTrip(capacity: 2);
        $booking = bookingRequest($departure, 2);

        $this->postJson("/api/v1/uae-en/travel/bookings/{$booking->number}/cancel", ['reason' => 'Plans changed'], ['X-Booking-Token' => $booking->access_token])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect($departure->refresh()->seats_reserved)->toBe(0);
        $this->postJson("/api/v1/uae-en/travel/bookings/{$booking->number}/cancel", ['reason' => 'Again'], ['X-Booking-Token' => $booking->access_token])->assertStatus(422);
    });

    it('moves a request to another departure only when that departure has the seats', function () {
        [$package, $departure] = activeTrip(capacity: 4);
        $other = app(TravelPackages::class)->addDeparture($package, now()->addDays(40)->toDateString(), now()->addDays(47)->toDateString(), 1, ['double' => '2000']);
        $booking = bookingRequest($departure);
        $bookings = app(TravelBookings::class);

        $change = $bookings->requestModification($booking, 'change_departure', ['departure_id' => $other->id], null);
        expect(fn () => $bookings->decideModification($change, true, 1))->toThrow(TransitionRefused::class, 'Only 1 seat(s) left');
        $other->update(['seat_capacity' => 5]);
        $bookings->decideModification($change->refresh(), true, 1, 'Moved');

        expect($booking->refresh()->travel_departure_id)->toBe($other->id)
            ->and($departure->refresh()->seats_reserved)->toBe(0)
            ->and($other->refresh()->seats_reserved)->toBe(2);
    });

    it('takes package inquiries from guests with contact details and mails the agency', function () {
        [$package] = activeTrip();

        $this->postJson("/api/v1/uae-en/travel/packages/{$package->slug}/inquiries", ['name' => 'Ali', 'message' => 'Kids prices?'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone'], 'error.fields');
        $this->postJson("/api/v1/uae-en/travel/packages/{$package->slug}/inquiries", ['name' => 'Ali', 'email' => 'ali@example.com', 'phone' => '0501234567', 'message' => 'Kids prices?'])->assertCreated();
        expect(TravelInquiry::query()->sole()->phone)->toBe('+971501234567');
        Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'travel.inquiry_sent');
    });
});

it('QC-028: sends the D4 confirmation in the language the customer booked in, whatever the agency panel uses', function () {
    [, $departure] = activeTrip(capacity: 4);
    app()->setLocale('ar');
    $booking = bookingRequest($departure, 2);
    app()->setLocale('en');

    app(TravelBookings::class)->confirm($booking, 1);

    expect($booking->refresh()->locale)->toBe('ar');
    Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail, array $channels, AnonymousNotifiable $notifiable): bool => $mail->event === 'travel.booking_confirmed'
        && $mail->options['locale'] === 'ar'
        && $mail->render($notifiable)->subject === 'تم تأكيد الحجز '.$booking->number
        && str_contains($mail->variables['trip'], 'عمرة ٧ ليال'));
});
