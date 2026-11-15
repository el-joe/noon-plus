<?php

use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Marketers\Support\WhatsAppMessenger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Models\VendorStrike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('local');
    Notification::fake();
    WhatsAppMessenger::fake();
});

/**
 * Sign in to an ops app through the API and return the token.
 */
function opsLogin(User $user, string $role, string $market = 'uae-en'): string
{
    app('auth')->forgetGuards();
    $token = test()->postJson("/api/v1/{$market}/ops/auth/login", ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => $role])
        ->assertOk()->json('data.token');
    app('auth')->forgetGuards();

    return $token;
}

/**
 * A shipping company of the current country with a supervisor (the owner).
 *
 * @return array{0: User, 1: Carrier}
 */
function opsCarrier(string $code = 'swiftops'): array
{
    $owner = User::factory()->create();
    $carrier = Carrier::query()->create(['code' => $code, 'name' => ucfirst($code).' Couriers', 'status' => 'active', 'owner_user_id' => $owner->id]);
    $owner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => app(CountryContext::class)->current()->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);

    return [$owner, $carrier];
}

function opsDriver(Carrier $carrier, string $status = 'active'): DeliveryAgent
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => app(CountryContext::class)->current()->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);

    return DeliveryAgent::query()->create(['user_id' => $user->id, 'carrier_id' => $carrier->id, 'name' => $user->name, 'status' => $status, 'phone' => '+971501112222', 'vehicle_type' => 'bike']);
}

/**
 * A packed COD parcel handed to the company.
 */
function opsParcel(Carrier $carrier): Shipment
{
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id, 'payment_method' => 'cod'])->save();

    return app(DeliveryDispatch::class)->allocate($subOrder->refresh(), $carrier, ActorType::Admin, 1);
}

/**
 * A travel agency (vendor of type travel) with its user and an approved
 * package with one departure in 30 days.
 *
 * @return array{0: User, 1: Vendor, 2: TravelDeparture}
 */
function opsAgency(int $capacity = 6): array
{
    $user = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays', 'owner_user_id' => $user->id]);
    $user->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => app(CountryContext::class)->current()->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id]);
    $packages = app(TravelPackages::class);
    $package = $packages->create($agency, [
        'title_en' => 'Umrah 7 nights', 'title_ar' => 'عمرة', 'destination_city' => 'Makkah', 'nights' => 7, 'trip_type' => 'umrah',
        'itinerary' => [['day' => 1, 'title' => 'Arrival']], 'inclusions' => ['Flights'],
        'cancellation_policy' => [['days_before' => 21, 'refund_percent' => 100]],
    ], 1);
    $departure = $packages->addDeparture($package, now()->addDays(30)->toDateString(), now()->addDays(37)->toDateString(), $capacity, ['double' => '2000.00']);
    $packages->attachContract($package, UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'));
    $packages->submit($package->refresh(), 1);
    $packages->approve($package->refresh(), 1);

    return [$user, $agency, $departure->refresh()];
}

function opsBooking(TravelDeparture $departure, int $travellers = 2): TravelBooking
{
    return app(TravelBookings::class)->request($departure, null, [['room_type' => 'double', 'travellers' => $travellers]], [
        'name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'phone' => '0501234567',
    ]);
}

describe('ops sign-in', function () {
    it('signs a seller in with an ops:vendor token, records the login and signs out', function () {
        [$user, $vendor] = sellerAccount('uae', ['store_name' => 'Gadget Hub']);

        $login = $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'vendor'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.workspace', ['role' => 'vendor', 'id' => $vendor->id, 'name' => 'Gadget Hub']);

        expect($user->tokens()->sole()->abilities)->toBe(['ops:vendor'])
            ->and(LoginEvent::query()->where('user_id', $user->id)->sole())->channel->toBe('ops_app');

        app('auth')->forgetGuards();
        $this->withToken($login['data']['token'])->postJson('/api/v1/uae-en/ops/auth/logout')->assertOk()->assertJsonPath('data.signed_out', true);
        expect($user->tokens()->count())->toBe(0);
        app('auth')->forgetGuards();
        $this->withToken($login['data']['token'])->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertUnauthorized();
    });

    it('refuses a wrong password, an unknown role and an account without the role workspace', function () {
        [$user] = sellerAccount('uae');

        $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'x', 'role' => 'vendor'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_credentials');
        $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

        foreach (['marketer', 'supervisor', 'travel_agency'] as $role) {
            $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x', 'role' => $role])
                ->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        }

        $this->postJson('/api/v1/ksa-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x', 'role' => 'vendor'])
            ->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        expect($user->tokens()->count())->toBe(0)
            ->and(LoginEvent::query()->where('channel', 'ops_app')->where('outcome', 'no_panel_access')->count())->toBe(4);
    });

    it('lets each token into its own role group only', function () {
        [$seller] = sellerAccount('uae');
        $vendorToken = opsLogin($seller, 'vendor');
        [, $customerToken] = customerWithToken();

        foreach (['marketer/dashboard', 'supervisor/dashboard', 'travel/dashboard'] as $path) {
            $this->withToken($vendorToken)->getJson("/api/v1/uae-en/ops/{$path}")->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
            app('auth')->forgetGuards();
        }

        $this->withToken($customerToken)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        app('auth')->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertUnauthorized();
    });
});

describe('vendor app', function () {
    beforeEach(function () {
        [$this->seller, $this->vendor] = sellerAccount('uae', ['store_name' => 'Gadget Hub']);
        $this->token = opsLogin($this->seller, 'vendor');
    });

    it('shows the store KPIs with money in the country currency', function () {
        $listing = sellableListing('100.00', stock: 50, vendor: $this->vendor);
        foreach ([SubOrderStatus::Placed, SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Cancelled] as $status) {
            subOrderFor($this->vendor, $status, listing: $listing);
        }
        subOrderFor($this->vendor, SubOrderStatus::Delivered, listing: $listing, placedAt: now()->subDays(3));
        subOrderFor($this->vendor, SubOrderStatus::Delivered, listing: $listing, placedAt: now()->subDays(10));
        sellableListing('50.00', stock: 2, vendor: $this->vendor);
        subOrderFor(Vendor::factory()->create(), SubOrderStatus::Placed);

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertOk()
            ->assertJsonPath('data.store', ['id' => $this->vendor->id, 'name' => 'Gadget Hub'])
            ->assertJsonPath('data.kpis.to_confirm', 1)
            ->assertJsonPath('data.kpis.to_pack', 2)
            ->assertJsonPath('data.kpis.ready_to_ship', 1)
            ->assertJsonPath('data.kpis.orders_today', 5)
            ->assertJsonPath('data.kpis.sales_today', ['amount' => '400.00', 'currency' => 'AED'])
            ->assertJsonPath('data.kpis.sales_7d', ['amount' => '500.00', 'currency' => 'AED'])
            ->assertJsonPath('data.kpis.low_stock', 1);
    });

    it('lists, shows, advances and cancels its own orders through the fulfilment steps', function () {
        $subOrder = subOrderFor($this->vendor, SubOrderStatus::Placed, quantity: 2);
        $base = "/api/v1/uae-en/ops/vendor/orders/{$subOrder->id}";

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/vendor/orders?status=placed')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', $subOrder->number)
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.total', ['amount' => '200.00', 'currency' => 'AED'])
            ->assertJsonPath('data.0.next_step', 'confirmed')
            ->assertJsonPath('data.0.next_step_label', 'Confirm order')
            ->assertJsonPath('data.0.can_cancel', true);
        $this->withToken($this->token)->getJson($base)->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', ['amount' => '100.00', 'currency' => 'AED']);

        $this->withToken($this->token)->postJson("{$base}/advance", ['to' => 'confirmed'])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.next_step', 'processing');
        $this->withToken($this->token)->postJson("{$base}/advance", ['to' => 'packed'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'transition_refused');
        expect($subOrder->refresh()->status)->toBe(SubOrderStatus::Confirmed)
            ->and($subOrder->statusHistory()->reorder('id', 'desc')->first()->actor_type->value)->toBe('vendor');

        $this->withToken($this->token)->postJson("{$base}/cancel", ['reason' => 'x'])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($this->token)->postJson("{$base}/cancel", ['reason' => 'Out of stock'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false)->assertJsonPath('data.next_step', null);
        expect(VendorStrike::query()->where('sub_order_id', $subOrder->id)->exists())->toBeTrue();
        $this->withToken($this->token)->postJson("{$base}/cancel", ['reason' => 'Out of stock'])->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
    });

    it('returns 404 for another store’s order or listing', function () {
        $theirs = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Placed);
        $theirListing = sellableListing(stock: 3);

        $this->withToken($this->token)->getJson("/api/v1/uae-en/ops/vendor/orders/{$theirs->id}")->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/vendor/orders/{$theirs->id}/advance", ['to' => 'confirmed'])->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/vendor/stock/{$theirListing->id}", ['quantity' => 5, 'reason' => 'restock'])->assertNotFound();
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/vendor/orders')->assertOk()->assertJsonCount(0, 'data');
        expect($theirs->refresh()->status)->toBe(SubOrderStatus::Placed);
    });

    it('lists stock with the low filter and moves stock through the ledger', function () {
        $low = sellableListing('20.00', stock: 3, vendor: $this->vendor);
        sellableListing('20.00', stock: 40, vendor: $this->vendor);

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/vendor/stock')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/vendor/stock?low=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.listing_id', $low->id)->assertJsonPath('data.0.stock', 3)
            ->assertJsonPath('data.0.low_stock_threshold', 5)->assertJsonPath('data.0.status', 'active');

        $url = "/api/v1/uae-en/ops/vendor/stock/{$low->id}";
        $this->withToken($this->token)->postJson($url, ['quantity' => 5, 'reason' => 'restock'])->assertOk()->assertJsonPath('data.stock', 8);
        $this->withToken($this->token)->postJson($url, ['quantity' => -2, 'reason' => 'damaged'])->assertOk()->assertJsonPath('data.stock', 6);
        $this->withToken($this->token)->postJson($url, ['quantity' => -50, 'reason' => 'lost'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'insufficient_stock')->assertJsonPath('error.fields.quantity.0', 'Only 6 units are in stock.');
        $this->withToken($this->token)->postJson($url, ['quantity' => 0, 'reason' => 'gift'])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity', 'reason'], 'error.fields');
        expect($low->movements()->where('reason', 'damaged')->value('actor_user_id'))->toBe($this->seller->id);
    });

    it('keeps the store to its own country', function () {
        [$ksaSeller] = sellerAccount('ksa');
        $ksaToken = opsLogin($ksaSeller, 'vendor', 'ksa-en');

        $this->withToken($ksaToken)->getJson('/api/v1/uae-en/ops/vendor/dashboard')->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        app('auth')->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/ksa-en/ops/vendor/orders')->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        app('auth')->forgetGuards();
        $this->withToken($ksaToken)->getJson('/api/v1/ksa-en/ops/vendor/dashboard')->assertOk()->assertJsonPath('data.kpis.sales_today.currency', 'SAR');
    });
});

describe('marketer app', function () {
    it('signs a marketer in and shows the Studio overview, campaigns, links and conversions', function () {
        marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
        [$user, $marketer] = marketerAccount(['affiliate'], attributes: ['display_name' => 'Sara Styles']);
        [, $other] = marketerAccount(['affiliate']);
        $vendor = Vendor::factory()->create(['store_name' => 'Glow Shop']);
        $campaign = liveCampaign($vendor);
        $invitation = app(Invitations::class)->joinOpen($campaign, $marketer);
        $theirs = app(Invitations::class)->joinOpen($campaign, $other);
        ReferralClick::query()->create(['invitation_id' => $invitation->id, 'campaign_id' => $campaign->id, 'marketer_profile_id' => $marketer->id, 'listing_id' => $campaign->listing_id, 'visitor_token' => 'v1', 'clicked_at' => now(), 'expires_at' => now()->addDays(7)]);
        $order = subOrderFor($vendor)->order;
        foreach ([[$invitation, $marketer, 'pending'], [$invitation, $marketer, 'reversed'], [$theirs, $other, 'pending']] as $index => [$inv, $owner, $status]) {
            MarketerConversion::query()->create([
                'campaign_id' => $campaign->id, 'invitation_id' => $inv->id, 'marketer_profile_id' => $owner->id, 'source_type' => 'order_item', 'source_id' => $index + 1,
                'order_id' => $order->id, 'attribution' => 'last_click', 'currency' => 'AED', 'basis' => '105.00', 'gross' => '105.00', 'fee' => '1.05', 'net' => '9.45', 'status' => $status,
            ]);
        }

        $login = $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'marketer'])
            ->assertOk()->assertJsonPath('data.workspace', ['role' => 'marketer', 'id' => $marketer->id, 'name' => 'Sara Styles']);
        $token = $login['data']['token'];
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/dashboard')->assertOk()
            ->assertJsonPath('data.profile.types', ['affiliate'])
            ->assertJsonPath('data.profile.code', $invitation->referral_code)
            ->assertJsonPath('data.kpis.clicks', 1)
            ->assertJsonPath('data.kpis.conversions', 1)
            ->assertJsonPath('data.kpis.sales', ['amount' => '105.00', 'currency' => 'AED'])
            ->assertJsonPath('data.kpis.earnings', ['amount' => '9.45', 'currency' => 'AED'])
            ->assertJsonPath('data.kpis.active_campaigns', 1);
        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/campaigns')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invitation->id)
            ->assertJsonPath('data.0.name', 'Launch')
            ->assertJsonPath('data.0.seller', 'Glow Shop')
            ->assertJsonPath('data.0.commission', '10%')
            ->assertJsonPath('data.0.conversions', 2)
            ->assertJsonPath('data.0.link', fn (string $link): bool => str_ends_with($link, '/r/'.$invitation->referral_code));
        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/links')->assertOk()
            ->assertJsonPath('data.referral_link', fn (string $link): bool => str_ends_with($link, '/r/'.$invitation->referral_code))
            ->assertJsonPath('data.links.0.campaign', 'Launch')->assertJsonPath('data.links.0.clicks', 1)->assertJsonPath('data.links.0.conversions', 1);
        $this->withToken($token)->getJson('/api/v1/uae-en/ops/marketer/conversions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.order_number', $order->number)
            ->assertJsonPath('data.0.status', 'reversed')
            ->assertJsonPath('data.0.amount', ['amount' => '105.00', 'currency' => 'AED'])
            ->assertJsonPath('data.0.commission', ['amount' => '9.45', 'currency' => 'AED']);
    });

    it('refuses an account without a marketer profile in the country', function () {
        [$user] = marketerAccount(['affiliate']);

        $this->postJson('/api/v1/ksa-en/ops/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x', 'role' => 'marketer'])
            ->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
    });
});

describe('supervisor app', function () {
    beforeEach(function () {
        [$this->owner, $this->carrier] = opsCarrier();
        $this->token = opsLogin($this->owner, 'supervisor');
    });

    it('shows the company KPIs and dispatch board, and assigns manually or automatically', function () {
        $driver = opsDriver($this->carrier);
        $shipment = opsParcel($this->carrier);
        $second = opsParcel($this->carrier);
        [, $rival] = opsCarrier('rivalops');
        $rivalShipment = opsParcel($rival);
        $rivalDriver = opsDriver($rival);

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/dashboard')->assertOk()
            ->assertJsonPath('data.company', ['id' => $this->carrier->id, 'name' => 'Swiftops Couriers'])
            ->assertJsonPath('data.kpis.unassigned', 2)->assertJsonPath('data.kpis.open_assignments', 0)
            ->assertJsonPath('data.kpis.drivers_active', 1)->assertJsonPath('data.kpis.cod_in_hand', ['amount' => '0.00', 'currency' => 'AED']);
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/dispatch')->assertOk()
            ->assertJsonCount(2, 'data.unassigned')
            ->assertJsonPath('data.unassigned.0.id', $shipment->id)
            ->assertJsonPath('data.unassigned.0.city', 'Dubai')
            ->assertJsonPath('data.unassigned.0.cod_amount.currency', 'AED')
            ->assertJsonPath('data.drivers.0', ['id' => $driver->id, 'name' => $driver->name, 'status' => 'active', 'open' => 0]);

        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/dispatch/{$rivalShipment->id}/assign", ['agent_id' => $driver->id])->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/dispatch/{$shipment->id}/assign", ['agent_id' => $rivalDriver->id])->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/dispatch/{$shipment->id}/assign", ['agent_id' => $driver->id])->assertOk()
            ->assertJsonPath('data.assignment.status', 'assigned')
            ->assertJsonPath('data.assignment.agent', ['id' => $driver->id, 'name' => $driver->name]);
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/dispatch/{$shipment->id}/assign", ['agent_id' => $driver->id])->assertUnprocessable()
            ->assertJsonPath('error.code', 'transition_refused');
        expect(DeliveryAssignment::query()->where('shipment_id', $shipment->id)->sole()->transitions()->first()->actor_type)->toBe('carrier');

        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/dispatch/{$second->id}/assign", ['agent_id' => null])->assertOk()
            ->assertJsonPath('data.assignment.agent.id', $driver->id);
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/dispatch')->assertOk()
            ->assertJsonCount(0, 'data.unassigned')->assertJsonCount(2, 'data.open')
            ->assertJsonPath('data.open.0.agent.id', $driver->id)->assertJsonPath('data.drivers.0.open', 2);
    });

    it('lists failed deliveries with their actions and reschedules, returns and confirms them', function () {
        $driver = opsDriver($this->carrier);
        $shipment = opsParcel($this->carrier);
        $assignment = app(DeliveryDispatch::class)->assign($shipment->subOrder, $driver, ActorType::Carrier, $this->owner->id, $this->carrier);
        $steps = app(AgentAssignments::class);
        $steps->accept($assignment, $driver->user_id);
        $steps->start($assignment->refresh(), $driver->user_id);
        $steps->fail($assignment->refresh(), $driver->user_id, 'not_home');

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/failed')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shipment->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.failure_reason', 'not_home')
            ->assertJsonPath('data.0.attempts', 1)
            ->assertJsonPath('data.0.last_failed_at', fn (?string $at): bool => $at !== null)
            ->assertJsonPath('data.0.actions', ['reschedule', 'return']);

        $base = "/api/v1/uae-en/ops/supervisor/failed/{$shipment->id}";
        $this->withToken($this->token)->postJson("{$base}/returned")->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson("{$base}/reschedule", ['date' => now()->subDays(3)->toDateString()])->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson("{$base}/return", [])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($this->token)->postJson("{$base}/return", ['reason' => 'Customer moved'])->assertOk()
            ->assertJsonPath('data.status', 'returning_to_origin')->assertJsonPath('data.actions', ['returned']);
        $this->withToken($this->token)->postJson("{$base}/returned")->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.actions', []);
        expect($shipment->refresh()->status)->toBe(ShipmentStatus::Returned)
            ->and($shipment->subOrder->refresh()->status)->toBe(SubOrderStatus::Cancelled);

        [, $rival] = opsCarrier('rivalops');
        $rivalShipment = opsParcel($rival);
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/failed/{$rivalShipment->id}/return", ['reason' => 'Not mine'])->assertNotFound();
    });

    it('lists drivers with COD in hand and suspends or reactivates them with the panel rules', function () {
        $driver = opsDriver($this->carrier);
        $pending = opsDriver($this->carrier, AgentStatus::PendingDocuments->value);
        [, $rival] = opsCarrier('rivalops');
        $rivalDriver = opsDriver($rival);

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/drivers')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $driver->id, 'status' => 'active', 'phone' => '+971501112222', 'vehicle' => ['type' => 'bike', 'plate' => null], 'open_assignments' => 0, 'cod_in_hand' => ['amount' => '0.00', 'currency' => 'AED']]);

        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/drivers/{$pending->id}/status", ['status' => 'active'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/drivers/{$driver->id}/status", ['status' => 'unavailable'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/drivers/{$driver->id}/status", ['status' => 'suspended', 'reason' => 'Late'])->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/drivers/{$driver->id}/status", ['status' => 'active'])->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/supervisor/drivers/{$rivalDriver->id}/status", ['status' => 'suspended'])->assertNotFound();
        expect($rivalDriver->refresh()->status)->toBe(AgentStatus::Active);
    });

    it('refuses a suspended company and other countries', function () {
        $this->withToken($this->token)->getJson('/api/v1/ksa-en/ops/supervisor/dashboard')->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
        app('auth')->forgetGuards();
        $this->actAsCountry('uae');
        $this->carrier->forceFill(['status' => 'suspended'])->save();
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/supervisor/dashboard')->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
    });
});

describe('travel agency app', function () {
    beforeEach(function () {
        [$this->user, $this->agency, $this->departure] = opsAgency();
        $this->token = opsLogin($this->user, 'travel_agency');
    });

    it('shows the agency KPIs, bookings and departures', function () {
        opsBooking($this->departure, 2);
        $confirmed = opsBooking($this->departure, 1);
        app(TravelBookings::class)->confirm($confirmed, $this->user->id);

        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/travel/dashboard')->assertOk()
            ->assertJsonPath('data.agency', ['id' => $this->agency->id, 'name' => 'Gulf Holidays'])
            ->assertJsonPath('data.kpis.pending_requests', 1)
            ->assertJsonPath('data.kpis.confirmed_this_month', 1)
            ->assertJsonPath('data.kpis.upcoming_departures', 1)
            ->assertJsonPath('data.kpis.seats_left', 3);
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/travel/bookings?status=requested')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.package', 'Umrah 7 nights')
            ->assertJsonPath('data.0.departure_date', $this->departure->departs_on->toDateString())
            ->assertJsonPath('data.0.travellers', 2)
            ->assertJsonPath('data.0.contact_name', 'Sara Ahmed')
            ->assertJsonPath('data.0.total', ['amount' => '4000.00', 'currency' => 'AED'])
            ->assertJsonPath('data.0.can_confirm', true)->assertJsonPath('data.0.can_reject', true);
        $this->withToken($this->token)->getJson('/api/v1/uae-en/ops/travel/departures')->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $this->departure->id, 'package' => 'Umrah 7 nights', 'starts_on' => $this->departure->departs_on->toDateString(),
                'ends_on' => $this->departure->returns_on->toDateString(), 'capacity' => 6, 'booked' => 3, 'seats_left' => 3, 'status' => $this->departure->refresh()->status,
            ]);
    });

    it('confirms and rejects requests and refuses a second decision', function () {
        $first = opsBooking($this->departure, 2);
        $second = opsBooking($this->departure, 2);

        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/bookings/{$first->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.can_confirm', false);
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/bookings/{$first->id}/reject", ['reason' => 'Changed my mind'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/bookings/{$second->id}/reject", ['reason' => 'no'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/bookings/{$second->id}/reject", ['reason' => 'Hotel is full'])->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        expect($second->refresh()->status)->toBe(BookingStatus::Rejected)->and($this->departure->refresh()->seats_reserved)->toBe(2);
    });

    it('resizes a departure but not below the seats taken, and keeps other agencies out', function () {
        opsBooking($this->departure, 4);
        $url = "/api/v1/uae-en/ops/travel/departures/{$this->departure->id}/capacity";

        $this->withToken($this->token)->postJson($url, ['capacity' => 3])->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson($url, ['capacity' => 10])->assertOk()
            ->assertJsonPath('data.capacity', 10)->assertJsonPath('data.booked', 4)->assertJsonPath('data.seats_left', 6);

        [$otherUser, , $otherDeparture] = opsAgency();
        $otherBooking = opsBooking($otherDeparture, 1);
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/departures/{$otherDeparture->id}/capacity", ['capacity' => 20])->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/uae-en/ops/travel/bookings/{$otherBooking->id}/confirm")->assertNotFound();
        expect($otherBooking->refresh()->status)->toBe(BookingStatus::Requested)->and($otherUser)->not->toBeNull();
    });
});

it('keeps a vendor token out of another country’s orders', function () {
    $ksa = Country::query()->where('code', 'ksa')->sole();
    [$seller] = sellerAccount('uae');
    $ksaOrder = app(CountryContext::class)->runAs($ksa, fn (): SubOrder => subOrderFor(Vendor::factory()->create()));
    $token = opsLogin($seller, 'vendor');

    $this->withToken($token)->getJson("/api/v1/ksa-en/ops/vendor/orders/{$ksaOrder->id}")->assertForbidden()->assertJsonPath('error.code', 'no_workspace');
});
