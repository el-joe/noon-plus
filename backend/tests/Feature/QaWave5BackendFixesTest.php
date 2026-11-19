<?php

use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Models\CartInventoryLock;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Content\Models\StaticPage;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Documents\Support\Documents;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\PushDeviceToken;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Support\FakeLiveMediaProvider;
use App\Domains\LiveMedia\Support\LiveStreams;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| QA wave 5a (backend): the remaining QA-0xx / QC-0xx minors and trivials
| that the backend owns. Support tickets, discovery and lifecycles have
| their own wave 5 files.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

it('QA-017 / QA-018: PDP stock is net of other shoppers\' live holds and the flash "remaining" never exceeds it', function () {
    $listing = sellableListing('100.00', stock: 10);
    $event = FlashSaleEvent::factory()->running()->inStatus(FlashSaleEventStatus::Live)->create(['max_qty_per_customer' => 5]);
    $deal = FlashSaleSubmission::query()->create([
        'flash_sale_event_id' => $event->id, 'listing_id' => $listing->id, 'vendor_id' => $listing->vendor_id,
        'original_price' => '100.00', 'flash_price' => '80.00', 'quantity_cap' => 20,
    ]);
    $deal->forceFill(['status' => FlashSaleSubmissionStatus::Live])->save();
    $cart = Cart::query()->create(['guest_token' => str_repeat('a', 48)]);
    CartInventoryLock::query()->create(['cart_id' => $cart->id, 'listing_id' => $listing->id, 'quantity' => 7, 'expires_at' => now()->addMinutes(10)]);
    $expired = Cart::query()->create(['guest_token' => str_repeat('b', 48)]);
    CartInventoryLock::query()->create(['cart_id' => $expired->id, 'listing_id' => $listing->id, 'quantity' => 2, 'expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/uae-en/products/'.$listing->variant->product->slug)->assertOk()
        ->assertJsonPath('data.buy_box.in_stock', 3)
        ->assertJsonPath('data.buy_box.flash_sale.remaining', 3);
});

it('QA-019: a viewing time without an offset is the market\'s local time', function () {
    $property = ClassifiedCategory::query()->create(['slug' => 'property', 'name_en' => 'Property', 'name_ar' => 'عقارات', 'vertical' => 'property']);
    $flats = ClassifiedCategory::query()->create(['parent_id' => $property->id, 'slug' => 'flats', 'name_en' => 'Flats', 'name_ar' => 'شقق']);
    $owner = User::factory()->create();
    $ad = app(ClassifiedAds::class)->create($owner->id, [
        'category_id' => $flats->id, 'title' => '2BR Marina', 'price' => '140000', 'city' => 'Dubai',
        'property' => ['purpose' => 'rent', 'property_type' => 'apartment', 'bedrooms' => 2, 'rent_period' => 'yearly', 'permit_number' => 'RERA-1', 'permit_authority' => 'DLD'],
    ]);
    app(ClassifiedAds::class)->addPhoto($ad, UploadedFile::fake()->image('flat.jpg'));
    app(ClassifiedAdMachine::class)->submit($ad->refresh(), ActorType::Customer, $owner->id);
    $ad = app(ClassifiedAdMachine::class)->approve($ad->refresh(), 1)->refresh();
    [$customer, $token] = customerWithToken();
    $local = now('Asia/Dubai')->addDays(3)->setTime(10, 0);

    $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$ad->number}/viewings", [
        'name' => 'Sara M.', 'email' => 'sara@example.test', 'phone' => '0501234567', 'preferred_at' => $local->format('Y-m-d H:i'),
    ])->assertCreated();

    expect(PropertyViewing::query()->sole()->preferred_at->utc()->format('H:i'))->toBe('06:00');
    $this->withToken($token)->getJson('/api/v1/uae-en/me/viewing-requests')->assertOk()
        ->assertJsonPath('data.0.preferred_at', $local->toIso8601String());
});

describe('classified ads (QA-020, QA-039, QA-040)', function () {
    beforeEach(function () {
        $this->cars = ClassifiedCategory::query()->create(['slug' => 'cars', 'name_en' => 'Cars', 'name_ar' => 'سيارات', 'vertical' => 'motors']);
        $this->cars->ownAttributes()->create(['key' => 'year', 'label_en' => 'Year', 'label_ar' => 'السنة', 'type' => 'year', 'min_value' => 1980, 'max_value' => 2027.5, 'is_filterable' => true]);
    });

    it('validates the contact phone per country and stores it as E.164', function () {
        [, $token] = customerWithToken();
        $ad = ['category_id' => $this->cars->id, 'title' => 'QA test car', 'price' => '1000'];

        $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', $ad + ['contact_phone' => 'not-a-phone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact_phone'], 'error.fields');
        $number = $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', $ad + ['contact_phone' => '050 123 4567'])->assertCreated()->json('data.number');

        expect(ClassifiedAd::query()->where('number', $number)->value('contact_phone'))->toBe('+971501234567');
    });

    it('refuses a price with more decimals than the currency has', function () {
        [, $token] = customerWithToken();

        $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $this->cars->id, 'title' => 'QA test car', 'price' => '1000.555'])
            ->assertUnprocessable()->assertJsonValidationErrors(['price'], 'error.fields');
        $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $this->cars->id, 'title' => 'QA test car', 'price' => '1000.55'])->assertCreated();
    });

    it('returns attribute bounds as numbers', function () {
        $year = collect($this->getJson('/api/v1/uae-en/classifieds/categories/cars/attributes')->assertOk()->json('data.attributes'))->firstWhere('key', 'year');

        expect($year['min'])->toBe(1980)->and($year['max'])->toBe(2027.5);
    });
});

it('QA-023: advertise budget bands are scaled to the market currency', function () {
    $this->getJson('/api/v1/uae-en/advertise-requests/options')->assertOk()
        ->assertJsonPath('data.budget_bands.0.max', 1000)->assertJsonPath('data.budget_bands.0.currency', 'AED');
    $this->getJson('/api/v1/omn-en/advertise-requests/options')->assertOk()
        ->assertJsonPath('data.budget_bands.0.max', 100)
        ->assertJsonPath('data.budget_bands.1.min', 100)->assertJsonPath('data.budget_bands.1.max', 500)
        ->assertJsonPath('data.budget_bands.1.label', 'OMR 100 – 500');

    expect(AdvertiseInquiry::budgetLabel('over_50000', 'KWD'))->toBe('Over KWD 5,000');
});

it('QA-024: a multi-shipment order names the shipment in each notification', function () {
    [$order, $customer] = placeOrderVia('cod');
    $second = SubOrder::query()->create(['order_id' => $order->id, 'vendor_id' => $order->subOrders->first()->vendor_id, 'number' => $order->number.'-2', 'subtotal' => '10', 'total' => '10']);

    moveSubOrderTo($second, SubOrderStatus::Confirmed);

    $notice = CustomerNotification::query()->where('user_id', $customer->id)->where('type', 'order.confirmed')->latest('id')->firstOrFail();
    expect($notice->title)->toContain($second->number)->toContain($order->number);
});

it('QA-025 / QA-042 / QA-043: delivery refusals say transition_refused, COD in hand is money and no internal address id leaks', function () {
    [$order, , $customerToken] = placeOrderVia('cod');

    $this->withToken($customerToken)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertOk()
        ->assertJsonMissingPath('data.shipping_address.address_id');

    $agentUser = User::factory()->create();
    $agentUser->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $this->country->id]);
    $agent = DeliveryAgent::query()->create(['user_id' => $agentUser->id, 'name' => 'Rashid', 'status' => 'active', 'phone' => '+971501112222']);
    $assignment = app(DeliveryDispatch::class)->assign(moveSubOrderTo($order->subOrders->first(), SubOrderStatus::Packed), $agent, ActorType::Admin, 1);
    app('auth')->forgetGuards();
    $token = $agentUser->createToken('phone', ['delivery'])->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/uae-en/delivery/assignments/{$assignment->number}/arrive")
        ->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
    $this->withToken($token)->getJson('/api/v1/uae-en/delivery/me')->assertOk()
        ->assertJsonPath('data.cod_in_hand', ['amount' => '0.00', 'currency' => 'AED']);
    $this->withToken($token)->getJson("/api/v1/uae-en/delivery/assignments/{$assignment->number}")->assertOk()
        ->assertJsonPath('data.customer.address.city', fn (?string $city): bool => $city !== null)
        ->assertJsonMissingPath('data.customer.address.address_id');
});

it('QA-032: the footer legal pages exist as clearly marked placeholders until an admin saves them', function () {
    foreach (['contact-us', 'terms-and-conditions', 'privacy-policy', 'return-policy', 'shipping-policy', 'refund-policy', 'cookie-policy'] as $slug) {
        $this->getJson("/api/v1/uae-en/static-pages/{$slug}")->assertOk()->assertJsonPath('data.placeholder', true)
            ->assertJsonPath('data.body', fn (string $body): bool => str_contains($body, 'Placeholder'));
    }

    $this->getJson('/api/v1/omn-ar/static-pages/privacy-policy')->assertOk()->assertJsonPath('data.body', fn (string $body): bool => str_contains($body, 'نص مؤقت'));

    $admin = adminAccount();
    $this->actingAs($admin)->post(onPanel('admin', 'cms/static-pages'), [
        'slug' => 'privacy-policy', 'scope' => 'global', 'audience' => 'both', 'title_en' => 'Privacy policy', 'title_ar' => 'سياسة الخصوصية',
        'body_en' => '<p>Final text.</p>', 'body_ar' => '<p>النص النهائي.</p>', 'is_published' => '1',
    ])->assertRedirect();

    expect(StaticPage::query()->whereNull('country_id')->where('slug', 'privacy-policy')->sole()->is_placeholder)->toBeFalse();
});

it('QA-035 / QC-071: product search matches % and _ literally', function () {
    sellableListing();
    sellableListing();

    $this->getJson('/api/v1/uae-en/products?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/uae-en/products?q=_')->assertOk()->assertJsonPath('meta.total', 0);
});

it('QA-037: booking room lines carry money objects', function () {
    $agency = Vendor::factory()->create(['type' => 'travel']);
    $packages = app(TravelPackages::class);
    $package = $packages->create($agency, [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Hotel'], 'cancellation_policy' => [['days_before' => 21, 'refund_percent' => 100]],
    ], 1);
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), 20, ['double' => '1500.00']);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    $this->postJson("/api/v1/uae-en/travel/departures/{$departure->id}/bookings", [
        'rooms' => [['room_type' => 'double', 'travellers' => 2]], 'name' => 'Sara M.', 'email' => 'sara@example.test', 'phone' => '0501234567', 'submit' => false,
    ])->assertCreated()
        ->assertJsonPath('data.rooms.0.unit_price', ['amount' => '1500.00', 'currency' => 'AED'])
        ->assertJsonPath('data.rooms.0.line_total', ['amount' => '3000.00', 'currency' => 'AED']);
});

it('QA-038: a method switched off in the country is named as unavailable, not as "not a card"', function () {
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '100', 'payment_method' => 'thawani', 'recipient_email' => 'friend@example.test'])
        ->assertUnprocessable()->assertJsonPath('error.message', 'This payment method is not available in United Arab Emirates.');
});

it('QA-041: shipment cancel takes only a known reason; `other` needs a note', function () {
    [$order, , $token] = placeOrderVia('cod');
    $shipment = $order->subOrders->first()->number;

    $this->withToken($token)->getJson('/api/v1/uae-en/orders/cancel-reasons')->assertOk()->assertJsonPath('data.0.code', 'changed_mind');
    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$shipment}/cancel", ['reason' => 'bogus'])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.fields');
    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$shipment}/cancel", ['reason' => 'other'])
        ->assertUnprocessable()->assertJsonValidationErrors(['note'], 'error.fields');
    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/shipments/{$shipment}/cancel", ['reason' => 'ordered_by_mistake'])
        ->assertOk()->assertJsonPath('data.shipments.0.status', 'cancelled');

    expect($order->subOrders->first()->statusHistory()->reorder('id', 'desc')->value('reason'))->toBe('ordered_by_mistake');
});

it('QA-044: a product vendor cannot open the classified "Post an ad" form', function () {
    [$seller] = sellerAccount('uae');

    $this->actingAs($seller)->get(onPanel('partner', 'marketplace/ads/create'))->assertForbidden();
});

it('QA-046: an expected business refusal is answered but not logged as an error', function () {
    Log::spy();
    [, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/gift-cards', ['amount' => '0.5', 'payment_method' => 'card', 'recipient_email' => 'friend@example.test'])->assertUnprocessable();

    Log::shouldNotHaveReceived('error');
});

it('QA-047: a refused first cart write leaves no guest cart and no token', function () {
    $listing = sellableListing(stock: 0);

    $response = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertUnprocessable();

    expect($response->headers->has('X-Cart-Token'))->toBeFalse()->and(Cart::query()->count())->toBe(0);
});

describe('customer orders list (QC-026, QC-050, G-A4)', function () {
    it('filters by tab, searches and pages, with counts per tab and actions per shipment', function () {
        [$delivered, $customer, $token] = placeOrderVia('cod');
        moveSubOrderTo($delivered->subOrders->first(), SubOrderStatus::Delivered);
        [$open] = placeOrderVia('cod', customer: $customer);
        app('auth')->forgetGuards();

        $all = $this->withToken($token)->getJson('/api/v1/uae-en/orders?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.counts.all', 2)->assertJsonPath('meta.counts.in_progress', 1)->assertJsonPath('meta.counts.delivered', 1);

        $this->withToken($token)->getJson('/api/v1/uae-en/orders?tab=delivered')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $delivered->number)
            ->assertJsonPath('data.0.shipments.0.actions.return', true)->assertJsonPath('data.0.shipments.0.actions.cancel', false)
            ->assertJsonPath('data.0.shipments.0.actions.review', true)->assertJsonPath('data.0.shipments.0.actions.invoice', true)
            ->assertJsonPath('data.0.shipments.0.items.0.listing_id', fn ($id) => is_int($id));
        $this->withToken($token)->getJson('/api/v1/uae-en/orders?tab=in_progress')->assertOk()
            ->assertJsonPath('data.0.number', $open->number)->assertJsonPath('data.0.shipments.0.actions.cancel', true)->assertJsonPath('data.0.shipments.0.actions.invoice', false);
        $this->withToken($token)->getJson('/api/v1/uae-en/orders?q='.urlencode($open->subOrders->first()->number))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $open->number);
        $this->withToken($token)->getJson('/api/v1/uae-en/orders?tab=bogus')->assertUnprocessable();

        expect($all->json('data'))->toHaveCount(1);
    });

    it('lists 20 orders with a fixed number of queries', function () {
        [$first, $customer, $token] = placeOrderVia('cod');
        app('auth')->forgetGuards();
        DB::connection($first->getConnectionName())->enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/uae-en/orders')->assertOk();
        $one = count(DB::connection($first->getConnectionName())->getQueryLog());

        foreach (range(1, 4) as $ignored) {
            placeOrderVia('cod', customer: $customer);
        }
        app('auth')->forgetGuards();
        DB::connection($first->getConnectionName())->flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/uae-en/orders')->assertOk()->assertJsonPath('meta.total', 5);

        expect(count(DB::connection($first->getConnectionName())->getQueryLog()))->toBe($one);
    });

    it('adds per_page and last_page to wallet, returns and my classified ads', function () {
        [, $token] = customerWithToken();

        foreach (['wallet/transactions', 'returns', 'classifieds/mine'] as $path) {
            $this->withToken($token)->getJson("/api/v1/uae-en/{$path}")->assertOk()
                ->assertJsonStructure(['meta' => ['total', 'page', 'per_page', 'last_page']]);
        }
    });
});

it('G-A6 / QC-054: a printable invoice once paid; the contract refusal uses the shared envelope', function () {
    [$order, , $token] = placeOrderVia('cod');

    $this->withToken($token)->get("/api/v1/uae-en/orders/{$order->number}/invoice", ['Accept' => 'application/json'])
        ->assertStatus(409)->assertJsonPath('error.code', 'invoice_not_ready');
    moveSubOrderTo($order->subOrders->first(), SubOrderStatus::Delivered);

    $this->withToken($token)->get("/api/v1/uae-en/orders/{$order->number}/invoice")->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Tax invoice')->assertSee($order->number)->assertSee($order->subOrders->first()->number);

    $error = ApiResponse::error('customer_contract_required', 'x', 409, extra: ['contracts' => [['version_id' => 1]]])->getData(true);
    expect($error['error'])->toMatchArray(['code' => 'customer_contract_required', 'contracts' => [['version_id' => 1]]])->toHaveKey('fields');
});

it('wave 5b apps: a signed 30-minute invoice link opens without a token; tampered or not-ready links are refused', function () {
    [$order, , $token] = placeOrderVia('cod');

    $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/invoice-link")
        ->assertStatus(409)->assertJsonPath('error.code', 'invoice_not_ready');
    moveSubOrderTo($order->subOrders->first(), SubOrderStatus::Delivered);

    $url = $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$order->number}/invoice-link")
        ->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']])->json('data.url');
    expect($url)->toContain("/api/v1/uae-en/orders/{$order->number}/invoice/signed")->toContain('signature=');

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertSee('Tax invoice')->assertSee($order->number);
    $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

    [, $other] = customerWithToken();
    app('auth')->forgetGuards();
    $this->withToken($other)->postJson("/api/v1/uae-en/orders/{$order->number}/invoice-link")->assertNotFound();
});

it('G-A2: a device registers and unregisters its push token; signing out drops it', function () {
    [$customer, $token] = customerWithToken();

    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('f', 40), 'platform' => 'android'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('f', 40), 'platform' => 'android'])->assertOk();
    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => 'x', 'platform' => 'pager'])->assertUnprocessable();
    expect(PushDeviceToken::query()->where('user_id', $customer->id)->count())->toBe(1);

    $this->withToken($token)->deleteJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('f', 40)])->assertOk();
    expect(PushDeviceToken::query()->count())->toBe(0);

    $this->withToken($token)->postJson('/api/v1/uae-en/me/push-tokens', ['token' => str_repeat('g', 40), 'platform' => 'ios'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/uae-en/auth/logout')->assertOk();
    expect(PushDeviceToken::query()->count())->toBe(0);
});

it('G-A5: the cart shows the free-delivery threshold and what is left to spend', function () {
    $listing = sellableListing('40.00');
    $threshold = config('nawy.checkout.defaults.uae.free_shipping_threshold');

    $response = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();

    expect($response->json('data.free_delivery.threshold.amount'))->toBe(number_format((float) $threshold, 2, '.', ''))
        ->and($response->json('data.free_delivery.remaining.amount'))->toBe(bcsub((string) $threshold, '40', 2))
        ->and($response->json('data.free_delivery.qualified'))->toBeFalse();
});

it('G-A6: saved cards are gateway tokens only — listed, defaulted and removed by their owner', function () {
    [$order, $customer, $token] = placeOrderVia('stripe');
    $payment = $order->payments()->reorder('id', 'desc')->firstOrFail();
    FakeGateway::willTokenise($payment->gateway_reference, ['token' => 'pm_fake_123', 'last4' => '4242', 'brand' => 'Visa', 'exp_month' => 12, 'exp_year' => 2030]);
    captureOrderPayment($order);

    $card = $this->withToken($token)->getJson('/api/v1/uae-en/me/cards')->assertOk()
        ->assertJsonPath('data.0.last4', '4242')->assertJsonPath('data.0.brand', 'visa')->assertJsonPath('data.0.is_default', true)
        ->assertJsonMissingPath('data.0.gateway_token')->json('data.0.id');
    expect(DB::connection($order->getConnectionName())->table('saved_payment_cards')->value('gateway_token'))->not->toBe('pm_fake_123');

    [, $otherToken] = customerWithToken();
    app('auth')->forgetGuards();
    $this->withToken($otherToken)->deleteJson("/api/v1/uae-en/me/cards/{$card}")->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($token)->deleteJson("/api/v1/uae-en/me/cards/{$card}")->assertOk();
    expect(SavedPaymentCard::query()->count())->toBe(0);
});

it('G-A8: countries carry both the English and the Arabic name', function () {
    $this->getJson('/api/v1/countries')->assertOk()->assertJsonPath('data.0.names.ar', fn ($name) => is_string($name) && $name !== '');
});

describe('live comments and viewers (QC-040)', function () {
    beforeEach(function () {
        app()->instance(LiveMediaProvider::class, new FakeLiveMediaProvider);
        $this->stream = app(LiveStreams::class)->createLive(['title_en' => 'Eid live', 'title_ar' => 'بث العيد'], 1);
    });

    it('takes comments only while live, holds filtered ones and lets an admin hide one', function () {
        [, $token] = customerWithToken();
        $base = "/api/v1/uae-en/live-streams/{$this->stream->slug}";

        $this->withToken($token)->postJson("{$base}/comments", ['body' => 'Hello'])->assertUnprocessable();
        app(LiveStreams::class)->goLive($this->stream, 1);
        app('auth')->forgetGuards();
        $this->withoutToken()->postJson("{$base}/comments", ['body' => 'Hi'])->assertUnauthorized();
        $id = $this->withToken($token)->postJson("{$base}/comments", ['body' => 'Love the colours'])->assertCreated()->assertJsonPath('data.status', 'visible')->json('data.id');
        $this->withToken($token)->postJson("{$base}/comments", ['body' => 'cheap at https://spam.example'])->assertCreated()->assertJsonPath('data.status', 'held');

        $this->getJson("{$base}/comments")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Love the colours');

        $admin = adminAccount();
        $this->actingAs($admin)->post(onPanel('admin', "media/{$this->stream->id}/comments/{$id}"), ['status' => 'hidden'])->assertRedirect();
        $this->getJson("{$base}/comments")->assertOk()->assertJsonCount(0, 'data');
    });

    it('counts viewers from heartbeats and keeps the peak and unique totals', function () {
        $base = "/api/v1/uae-en/live-streams/{$this->stream->slug}";
        $this->postJson("{$base}/presence", ['viewer_id' => 'device-aaaa-1'])->assertOk()->assertJsonPath('data.viewers', 0);
        app(LiveStreams::class)->goLive($this->stream, 1);

        $this->postJson("{$base}/presence", ['viewer_id' => 'device-aaaa-1'])->assertOk()->assertJsonPath('data.viewers', 1);
        $this->postJson("{$base}/presence", ['viewer_id' => 'device-bbbb-2'])->assertOk()->assertJsonPath('data.viewers', 2);
        $this->postJson("{$base}/presence", ['viewer_id' => 'device-aaaa-1'])->assertOk()->assertJsonPath('data.viewers', 2);
        $this->travel(2)->minutes();
        $this->postJson("{$base}/presence", ['viewer_id' => 'device-cccc-3'])->assertOk()->assertJsonPath('data.viewers', 1);

        expect($this->stream->refresh()->peak_viewers)->toBe(2)->and($this->stream->total_viewers)->toBe(3);
        $this->getJson($base)->assertOk()->assertJsonPath('data.viewers', 1);
    });

    it('QC-063: the home floating player follows the country setting', function () {
        $this->getJson('/api/v1/uae-en/live-streams/floating')->assertOk()->assertJsonPath('data', null);
        app(LiveStreams::class)->goLive($this->stream, 1);
        $this->getJson('/api/v1/uae-en/live-streams/floating')->assertOk()->assertJsonPath('data.slug', $this->stream->slug);

        $this->actingAs(adminAccount())->post(onPanel('admin', 'media/floating'), ['mode' => 'off'])->assertRedirect();
        $this->getJson('/api/v1/uae-en/live-streams/floating')->assertOk()->assertJsonPath('data', null);
    });
});

describe('panels', function () {
    it('QC-041: a seller hears about new orders and returns in the panel bell, apart from their customer notices', function () {
        [$seller, $vendor] = sellerAccount('uae');
        $subOrder = subOrderFor($vendor);
        app(SubOrderStateMachine::class)->transition($subOrder, SubOrderStatus::Confirmed, ActorType::Admin, 1);

        $notifications = app(PanelNotifications::class);
        expect($notifications->unreadCount($seller->id, 'partner'))->toBe(1)
            ->and(app(CustomerNotifications::class)->unreadCount($seller->id))->toBe(0);

        $this->actingAs($seller)->get(onPanel('partner'))->assertOk()->assertSee('data-panel-bell', false)->assertSee('data-unread-count', false);
        $this->actingAs($seller)->get(onPanel('partner', 'notifications'))->assertOk()->assertSee($subOrder->number);
        $id = $notifications->forUser($seller->id, 'partner')->value('id');
        $this->actingAs($seller)->post(onPanel('partner', "notifications/{$id}/read"))->assertRedirect('/orders/'.$subOrder->id);
        expect($notifications->unreadCount($seller->id, 'partner'))->toBe(0);
    });

    it('QC-044: a panel user sees their own sign-in history', function () {
        $admin = adminAccount();
        $admin->loginEvents()->create(['email' => $admin->email, 'channel' => 'admin', 'outcome' => 'succeeded', 'ip_address' => '203.0.113.9']);

        $this->actingAs($admin)->get(onPanel('admin', 'account/security'))->assertOk()->assertSee('203.0.113.9');
    });

    it('QC-062: the seller dashboard shows open returns and downloads a sales report', function () {
        [$seller, $vendor] = sellerAccount('uae');
        $subOrder = subOrderFor($vendor, SubOrderStatus::Delivered);

        $this->actingAs($seller)->get(onPanel('partner'))->assertOk()->assertSee('data-kpi="returns-open"', false)->assertDontSee('data-kpi="active-listings"', false);
        $csv = $this->actingAs($seller)->get(onPanel('partner', 'dashboard/report'))->assertOk()->streamedContent();

        expect($csv)->toContain('shipment,order,placed_at_local')->toContain($subOrder->number);
    });

    it('QC-070: welcome links use the configured scheme', function () {
        config(['app.url' => 'https://nawy.example']);

        expect(Panel::find('partner')->url('/login'))->toStartWith('https://');
    });
});

it('QC-073: CORS allows the storefront origin only', function () {
    $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/countries');
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')->not->toBe('*');
    $this->withHeaders(['Origin' => rtrim((string) config('nawy.payments.storefront_url'), '/')])->getJson('/api/v1/countries')
        ->assertHeader('Access-Control-Allow-Origin', rtrim((string) config('nawy.payments.storefront_url'), '/'));
});

it('QC-074: uploads are stored with the detected extension, never the client\'s', function () {
    foreach ([Documents::class, ContractPublisher::class] as $class) {
        expect(file_get_contents((new ReflectionClass($class))->getFileName()))->not->toContain('getClientOriginalExtension')->toContain('extension()');
    }
});

it('QC-051: the job and queue filters have indexes', function () {
    $schema = Schema::connection((new SubOrder)->getConnectionName());

    expect($schema->hasIndex('sub_orders', ['status', 'delivered_at']))->toBeTrue()
        ->and($schema->hasIndex('orders', ['status', 'placed_at']))->toBeTrue()
        ->and($schema->hasIndex('product_questions', ['status', 'created_at']))->toBeTrue()
        ->and($schema->hasIndex('carrier_claims', ['status', 'created_at']))->toBeTrue();
});
