<?php

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Customers\Models\WishlistItem;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Notifications\EmailLoginCodeNotification;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Support\Viewings;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketRequesterType;
use App\Domains\Support\Support\SupportTickets;
use App\Domains\Support\Support\TicketRequester;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\Email\EmailAddress;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\Notifications\Email\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
});

/**
 * @return Collection<int, EmailLog>
 */
function accountEmailLogs(string $event): Collection
{
    return EmailLog::query()->where('event', $event)->orderBy('id')->get();
}

/**
 * A live Open Souq ad of the given owner (general vertical).
 */
function accountEmailsAd(User $owner): ClassifiedAd
{
    $root = ClassifiedCategory::query()->firstOrCreate(['slug' => 'mail-root'], ['name_en' => 'General', 'name_ar' => 'قسم', 'vertical' => 'general']);
    $child = ClassifiedCategory::query()->firstOrCreate(['slug' => 'mail-child'], ['parent_id' => $root->id, 'name_en' => 'Child', 'name_ar' => 'فرعي']);
    $ad = app(ClassifiedAds::class)->create($owner->id, ['category_id' => $child->id, 'title' => 'iPhone 15', 'price' => '2500', 'city' => 'Dubai']);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('a.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);

    return app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh();
}

it('welcomes a new customer on the first sign-in only', function () {
    $signIn = function (): void {
        $code = app(RequestEmailLoginCode::class)->issue('nora@example.com', 'login');
        $this->postJson('/api/v1/uae-en/auth/email-code/verify', ['email' => 'nora@example.com', 'code' => $code, 'device_name' => 'iPhone'])->assertOk();
        app('auth')->forgetGuards();
    };

    $signIn();
    $signIn();

    expect(sentCatalogueEmails('account.welcome', 'nora@example.com'))->toHaveCount(1);
    expect(accountEmailLogs('account.welcome'))->toHaveCount(1)
        ->and(accountEmailLogs('auth.new_device'))->toHaveCount(0);
});

it('emails a sign-in from a new device, but not the first sign-in or a known device', function () {
    Notification::fake();
    $user = User::factory()->create();
    $record = fn (string $agent, string $ip = '10.0.0.1') => app(RecordLoginEvent::class)->handle(
        Request::create('/', 'POST', server: ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => $ip]), 'partner', LoginOutcome::Succeeded, $user,
    );
    $windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    $record($windows);
    $record($windows);
    Notification::assertNothingSent();

    $record('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', '10.0.0.2');

    Notification::assertSentTo($user, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'auth.new_device'
        && $mail->options['panel'] === 'partner'
        && $mail->variables['ip_address'] === '10.0.0.2'
        && $mail->variables['device']['r'] === ['browser' => 'Safari', 'system' => 'iOS']);
    expect(accountEmailLogs('auth.new_device'))->toHaveCount(1);
});

it('tells the customer when the password changes', function () {
    Notification::fake();
    [$user, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/me/password', ['current_password' => 'password', 'password' => 'new-secret-12', 'password_confirmation' => 'new-secret-12'])->assertOk();

    Notification::assertSentTo($user, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'auth.password_changed');
});

it('sends the email change notice to the old and the new address', function () {
    Notification::fake();
    [$user, $token] = customerWithToken();
    $old = $user->email;

    $this->withToken($token)->postJson('/api/v1/uae-en/me/email', ['email' => 'new@example.com'])->assertStatus(202);
    $code = null;
    Notification::assertSentOnDemand(EmailLoginCodeNotification::class, function (EmailLoginCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return $notification->purpose === 'email_change';
    });
    $this->withToken($token)->postJson('/api/v1/uae-en/me/email/verify', ['email' => 'new@example.com', 'code' => $code])->assertOk();

    Notification::assertSentTo($user->refresh(), EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'auth.email_changed' && $mail->variables['old_email'] === $old && $mail->variables['new_email'] === 'new@example.com');
    Notification::assertSentOnDemand(EventEmail::class, fn (EventEmail $mail, array $channels, AnonymousNotifiable $to): bool => $mail->event === 'auth.email_changed' && array_key_first((array) $to->routes['mail']) === $old);
    expect(accountEmailLogs('auth.email_changed')->pluck('recipient_hash')->sort()->values()->all())
        ->toBe(collect([EmailAddress::hash($old), EmailAddress::hash('new@example.com')])->sort()->values()->all());
});

it('emails the requester when support opens and answers a ticket', function () {
    Notification::fake();
    [$customer] = customerWithToken();
    $tickets = app(SupportTickets::class);

    $ticket = $tickets->open(TicketRequester::customer($customer->id), TicketCategory::for(TicketRequesterType::Customer)[0], 'Where is my order?', 'It is late.');
    $tickets->supportReply($ticket, adminAccount()->id, 'It ships today.');

    Notification::assertSentTo($customer, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'support.ticket_created');
    Notification::assertSentTo($customer, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'support.ticket_reply'
        && $mail->variables['reply'] === 'It ships today.'
        && str_contains($mail->options['url'], '/account/support/'.$ticket->number)
        && ! isset($mail->options['panel']));
});

it('tells the owner when the Open Souq ad is approved', function () {
    Notification::fake();
    $owner = User::factory()->create();

    $ad = accountEmailsAd($owner);

    Notification::assertSentTo($owner, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'classified.approved' && $mail->variables['ad_number'] === $ad->number);
    expect(accountEmailLogs('classified.approved'))->toHaveCount(1);
});

it('mails a viewing request to the seller only, never a receipt to the customer (D13)', function () {
    $owner = User::factory()->create();
    $ad = accountEmailsAd($owner);
    Notification::fake();

    app(Viewings::class)->request($ad, null, ['name' => 'Sara M.', 'email' => 'sara@example.com', 'phone' => '0501234567'], now()->addDay());

    Notification::assertSentTo($owner, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'viewing.requested' && $mail->variables['phone'] === '+971501234567');
    Notification::assertSentOnDemandTimes(EventEmail::class, 0);
    expect(accountEmailLogs('viewing.requested'))->toHaveCount(1)
        ->and(accountEmailLogs('viewing.requested')->first()->user_id)->toBe($owner->id);
});

it('confirms a guest travel booking in the language it was booked in', function () {
    Notification::fake();
    $packages = app(TravelPackages::class);
    $package = $packages->create(Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays']), [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة ٧ ليال', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Hotel'],
    ], 1);
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), 4, ['double' => '2000.00']);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    app()->setLocale('ar');
    $booking = app(TravelBookings::class)->request($departure->refresh(), null, [['room_type' => 'double', 'travellers' => 2]], ['name' => 'Sara', 'email' => 'guest@example.com', 'phone' => '0501234567']);
    app()->setLocale('en');
    app(TravelBookings::class)->confirm($booking, 1);

    Notification::assertSentOnDemand(EventEmail::class, function (EventEmail $mail, array $channels, AnonymousNotifiable $to) use ($booking): bool {
        if ($mail->event !== 'travel.booking_confirmed') {
            return false;
        }

        $rendered = $mail->render($to);

        return array_key_first((array) $to->routes['mail']) === 'guest@example.com'
            && $rendered->subject === 'تم تأكيد الحجز '.$booking->number
            && str_contains($rendered->html, 'dir="rtl"')
            && str_contains($rendered->html, 'عمرة ٧ ليال');
    });
    expect(accountEmailLogs('travel.booking_confirmed')->sole())->locale->toBe('ar')->user_id->toBeNull();
});

it('emails wishlisters when a sold-out product is back in stock or cheaper', function () {
    $listing = sellableListing('80.00', 1);
    $customer = User::factory()->create(['email' => 'fan@example.com']);
    WishlistItem::query()->create(['customer_user_id' => $customer->id, 'product_id' => $listing->variant->product_id]);
    $ledger = app(InventoryLedger::class);

    $ledger->move($listing, -1, 'sale');
    expect($listing->refresh()->status)->toBe(ListingStatus::OutOfStock);
    $ledger->move($listing, 5, 'restock');

    expect(sentCatalogueEmails('wishlist.back_in_stock', 'fan@example.com'))->toHaveCount(1);
    expect(accountEmailLogs('wishlist.back_in_stock'))->toHaveCount(1);

    $listing->refresh()->update(['price' => '60.00']);

    expect($listing->refresh())->previous_price->not->toBeNull()->price_dropped_at->not->toBeNull()
        ->and(accountEmailLogs('wishlist.price_drop'))->toHaveCount(1);
});

it('alerts the seller about low stock once per listing per day', function () {
    Notification::fake();
    config(['nawy.mail.reminders.low_stock_threshold' => 5]);
    $seller = User::factory()->create();
    $listing = sellableListing('50.00', 10, vendor: Vendor::factory()->create(['owner_user_id' => $seller->id]));
    $ledger = app(InventoryLedger::class);

    $ledger->move($listing, -4, 'sale');
    expect(accountEmailLogs('seller.low_stock'))->toHaveCount(0);

    $ledger->move($listing, -2, 'sale');
    $ledger->move($listing, -1, 'sale');
    expect(accountEmailLogs('seller.low_stock'))->toHaveCount(1);
    Notification::assertSentTo($seller, EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'seller.low_stock' && $mail->variables['quantity'] === '4');

    $this->travel(1)->days();
    $ledger->move($listing, -1, 'sale');
    expect(accountEmailLogs('seller.low_stock'))->toHaveCount(2)
        ->and(accountEmailLogs('seller.low_stock')->pluck('user_id')->unique()->all())->toBe([$seller->id]);
});
