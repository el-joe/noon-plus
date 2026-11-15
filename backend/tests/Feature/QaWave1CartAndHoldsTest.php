<?php

use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Listings\Models\Listing;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    Storage::fake('local');
    Notification::fake();
});

/**
 * @return array{0: TravelDeparture}
 */
function wave1Departure(): array
{
    $packages = app(TravelPackages::class);
    $agency = Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays']);
    $package = $packages->create($agency, [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Hotel'],
        'cancellation_policy' => [['days_before' => 21, 'refund_percent' => 100]],
    ], 1);
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), 20, ['double' => '2000.00']);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    return [$departure->refresh()];
}

function wave1Deal(Listing $listing, string $flashPrice, int $maxPerCustomer = 2): FlashSaleSubmission
{
    $event = FlashSaleEvent::factory()->running()->inStatus(FlashSaleEventStatus::Live)->create(['max_qty_per_customer' => $maxPerCustomer]);
    $submission = FlashSaleSubmission::query()->create([
        'flash_sale_event_id' => $event->id, 'listing_id' => $listing->id, 'vendor_id' => $listing->vendor_id,
        'original_price' => $listing->effectivePrice(), 'flash_price' => $flashPrice, 'quantity_cap' => 10,
    ]);
    $submission->forceFill(['status' => FlashSaleSubmissionStatus::Live])->save();

    return $submission;
}

it('QA-008: merges the guest cart past a flash-sale limit, reports the clipped line and releases every guest hold', function () {
    [, $token] = customerWithToken();
    $deal = sellableListing('100.00', stock: 10);
    wave1Deal($deal, '79.00', maxPerCustomer: 2);
    $other = sellableListing('50.00', stock: 10);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $deal->id, 'quantity' => 2])->assertOk();
    app('auth')->forgetGuards();
    $this->withoutToken();
    $guestToken = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $deal->id, 'quantity' => 1])->assertOk()->headers->get('X-Cart-Token');
    $this->withHeader('X-Cart-Token', $guestToken)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $other->id, 'quantity' => 3])->assertOk();

    $response = $this->withToken($token)->withHeader('X-Cart-Token', $guestToken)->postJson('/api/v1/uae-en/cart/merge')->assertOk();

    $response->assertJsonPath('meta.merge_warnings.0.listing_id', $deal->id)
        ->assertJsonPath('meta.merge_warnings.0.code', 'flash_sale_customer_limit')
        ->assertJsonCount(1, 'meta.merge_warnings');
    expect(collect($response->json('data.shipments'))->flatMap(fn ($shipment) => $shipment['items'])->pluck('quantity', 'listing_id')->all())
        ->toEqual([$deal->id => 2, $other->id => 3])
        ->and(Cart::query()->where('guest_token', $guestToken)->exists())->toBeFalse()
        ->and((int) CartInventoryLock::query()->where('listing_id', $other->id)->sum('quantity'))->toBe(3);
});

it('QA-008: an unexpected API error is a plain envelope without a trace, even with APP_DEBUG on', function () {
    config(['app.debug' => true]);
    Route::get('api/v1/uae-en/boom-test', fn () => throw new RuntimeException('secret internals'));

    $this->getJson('/api/v1/uae-en/boom-test')->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonMissingPath('trace')
        ->assertDontSee('secret internals');
});

it('QA-010: a guest line holds only a few units and a hold is never renewed past its cap', function () {
    $listing = sellableListing('100.00', stock: 10);

    $guestToken = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 10])->assertOk()->headers->get('X-Cart-Token');
    expect((int) CartInventoryLock::query()->sum('quantity'))->toBe(3);

    [, $token] = customerWithToken();
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 7])->assertOk();
    app('auth')->forgetGuards();
    $this->withoutToken();

    $this->travel(50)->minutes();
    $this->withHeader('X-Cart-Token', $guestToken)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
    $guestLock = Cart::query()->where('guest_token', $guestToken)->sole()->locks()->sole();
    expect($guestLock->expires_at->lte($guestLock->created_at->copy()->addMinutes(60)))->toBeTrue();

    $this->travel(11)->minutes();
    $this->withHeader('X-Cart-Token', $guestToken)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
    expect($guestLock->refresh()->expires_at->isPast())->toBeTrue();
});

it('QA-010: guests cannot hold many seats or keep re-holding a departure', function () {
    [$departure] = wave1Departure();
    $booking = fn (int $travellers, bool $submit = false) => $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", [
        'name' => 'Guest', 'email' => 'guest@example.com', 'phone' => '0501112222',
        'rooms' => [['room_type' => 'double', 'travellers' => $travellers]], 'submit' => $submit,
    ]);

    $booking(6)->assertStatus(422);
    $booking(2)->assertCreated();
    $booking(2)->assertCreated();
    $booking(1)->assertTooManyRequests();
    $booking(1, true)->assertCreated();
});

it('QA-014 / QA-015: coupon codes are throttled and throttle counters are per route and market', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/uae-en/cart/coupons', ['code' => "C{$i}"])->assertStatus(422);
    }
    $this->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'C11'])->assertTooManyRequests();

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-pass', 'device_name' => 'x', 'role' => 'vendor']);
    }
    $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-pass', 'device_name' => 'x', 'role' => 'vendor'])->assertTooManyRequests();
    $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => 'other@example.com', 'password' => 'wrong-pass', 'device_name' => 'x', 'role' => 'vendor'])->assertStatus(422);
    $this->postJson('/api/v1/uae-en/delivery/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-pass', 'device_name' => 'x'])->assertStatus(422);

    for ($i = 0; $i < 11; $i++) {
        $this->getJson('/api/v1/uae-en/partner-applications/check-slug?slug=shop-'.$i);
    }
    $this->postJson('/api/v1/uae-en/partner-applications', ['type' => 'vendor'])->assertStatus(422);
});
