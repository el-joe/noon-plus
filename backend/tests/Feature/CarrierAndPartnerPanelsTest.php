<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Domains\Settings\SettingsService;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Notification::fake();
    $this->dispatch = app(DeliveryDispatch::class);
});

/**
 * A shipping company with its owner supervisor.
 *
 * @return array{0: User, 1: Carrier}
 */
function shippingCompany(string $code = 'fastlane', ?Country $country = null): array
{
    $country ??= app(CountryContext::class)->current();
    $owner = User::factory()->create();
    $carrier = app(CountryContext::class)->runAs($country, fn (): Carrier => Carrier::query()->create(['code' => $code, 'name' => ucfirst($code).' Couriers', 'status' => 'active', 'owner_user_id' => $owner->id]));
    $owner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);

    return [$owner, $carrier];
}

function companyAgent(?Carrier $carrier, string $status = 'active'): DeliveryAgent
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => app(CountryContext::class)->current()->id, 'tenant_type' => $carrier ? Carrier::TENANT : null, 'tenant_id' => $carrier?->id]);

    return DeliveryAgent::query()->create(['user_id' => $user->id, 'carrier_id' => $carrier?->id, 'name' => $user->name, 'status' => $status]);
}

/**
 * Open the invitation mailed to `$email` and accept it (choosing a
 * password when the email has no account yet).
 */
function acceptTeamInvitation(string $panel, string $email, ?string $password = null): void
{
    $url = null;
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($notification, array $channels, $notifiable) use ($email, &$url): bool {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $url = $notification->acceptUrl;
        }

        return true;
    });
    expect($url)->not->toBeNull();
    $current = auth()->user();
    auth()->logout();
    test()->get($url)->assertOk();
    test()->post($url, $password === null ? [] : ['password' => $password, 'password_confirmation' => $password])->assertRedirect(onPanel($panel, 'login'))->assertSessionHasNoErrors();

    if ($current !== null) {
        test()->actingAs($current);
    }
}

function readyParcel(): SubOrder
{
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id, 'payment_method' => 'cod'])->save();

    return $subOrder->refresh();
}

describe('carrier panel', function () {
    it('shows every Figma page scoped to the company and assigns handed-over parcels to its own drivers only', function () {
        [$owner, $carrier] = shippingCompany();
        [, $rival] = shippingCompany('rival');
        $driver = companyAgent($carrier);
        $rivalDriver = companyAgent($rival);
        $mine = readyParcel();
        $theirs = readyParcel();
        $this->dispatch->allocate($mine, $carrier, ActorType::Admin, 1);
        $theirShipment = $this->dispatch->allocate($theirs, $rival, ActorType::Admin, 1);
        $myShipment = Shipment::query()->where('sub_order_id', $mine->id)->sole();

        foreach (['', 'dispatch', 'pickups', 'shipments', 'assignments', 'exceptions', 'agents', 'cod', 'claims', 'company'] as $page) {
            $this->actingAs($owner)->get(onPanel('carrier', $page))->assertOk();
        }

        $this->actingAs($owner)->get(onPanel('carrier', 'pickups'))->assertSee($myShipment->number)->assertDontSee($theirShipment->number);
        $this->actingAs($owner)->post(onPanel('carrier', "pickups/{$theirShipment->id}/assign"), ['agent_id' => $driver->id])->assertNotFound();
        $this->actingAs($owner)->post(onPanel('carrier', "pickups/{$myShipment->id}/assign"), ['agent_id' => $rivalDriver->id])->assertNotFound();
        expect(fn () => $this->dispatch->assign($theirs, $driver, ActorType::Carrier, $owner->id, $carrier))->toThrow(TransitionRefused::class, 'not handed to your company');

        $this->actingAs($owner)->post(onPanel('carrier', "pickups/{$myShipment->id}/assign"), ['agent_id' => $driver->id])->assertRedirect()->assertSessionHasNoErrors();
        $assignment = DeliveryAssignment::query()->sole();
        expect($assignment->delivery_agent_id)->toBe($driver->id)->and($assignment->carrier_id)->toBe($carrier->id)
            ->and($assignment->transitions()->first()->actor_type)->toBe('carrier');

        $other = companyAgent($carrier);
        $this->actingAs($owner)->post(onPanel('carrier', "assignments/{$assignment->id}/reassign"), ['agent_id' => $other->id])->assertRedirect();
        expect($assignment->refresh()->status)->toBe(AssignmentStatus::Reassigned);
        $this->actingAs($owner)->get(onPanel('carrier', 'assignments'))->assertSee($assignment->number);
    });

    it('handles failed deliveries for its own shipments', function () {
        [$owner, $carrier] = shippingCompany();
        $driver = companyAgent($carrier);
        $parcel = readyParcel();
        $this->dispatch->allocate($parcel, $carrier, ActorType::Admin, 1);
        $assignment = $this->dispatch->assign($parcel, $driver, ActorType::Carrier, $owner->id, $carrier);
        $steps = app(AgentAssignments::class);
        $steps->accept($assignment, $driver->user_id);
        $steps->start($assignment->refresh(), $driver->user_id);
        $steps->fail($assignment->refresh(), $driver->user_id, 'not_home');
        $shipment = Shipment::query()->sole();

        $this->actingAs($owner)->get(onPanel('carrier', 'exceptions'))->assertOk()->assertSee($shipment->number)->assertSee('not home');
        $this->actingAs($owner)->post(onPanel('carrier', "shipments/{$shipment->id}/return"), ['reason' => 'Customer moved'])->assertRedirect();
        expect($shipment->refresh()->status)->toBe(ShipmentStatus::ReturningToOrigin);
        $this->actingAs($owner)->post(onPanel('carrier', "shipments/{$shipment->id}/returned"))->assertRedirect();
        expect($shipment->refresh()->status)->toBe(ShipmentStatus::Returned)->and($parcel->refresh()->status)->toBe(SubOrderStatus::Cancelled);

        [$rivalOwner] = shippingCompany('rival');
        $this->actingAs($rivalOwner)->post(onPanel('carrier', "shipments/{$shipment->id}/return"), ['reason' => 'x'])->assertNotFound();
    });

    it('lets supervisors manage drivers but only the owner adds supervisors or edits the company', function () {
        [$owner, $carrier] = shippingCompany();
        $supervisor = User::factory()->create();
        $supervisor->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);

        $this->actingAs($supervisor)->post(onPanel('carrier', 'agents'), ['name' => 'Rashid', 'email' => 'rashid@example.com', 'phone' => '0501112222', 'vehicle_type' => 'van'])->assertRedirect()->assertSessionHasNoErrors();
        expect(DeliveryAgent::query()->count())->toBe(0);
        acceptTeamInvitation('carrier', 'rashid@example.com', 'driver-pass-1');
        $agent = DeliveryAgent::query()->where('name', 'Rashid')->sole();
        expect($agent->carrier_id)->toBe($carrier->id)->and($agent->status)->toBe(AgentStatus::PendingDocuments)->and($agent->phone)->toBe('+971501112222');

        $this->actingAs($supervisor)->post(onPanel('carrier', "agents/{$agent->id}/status"), ['status' => 'active'])->assertSessionHasErrors('action');
        $agent->forceFill(['status' => AgentStatus::Active])->save();
        $this->actingAs($supervisor)->post(onPanel('carrier', "agents/{$agent->id}/status"), ['status' => 'suspended', 'reason' => 'Late'])->assertRedirect();
        expect($agent->refresh()->status)->toBe(AgentStatus::Suspended);

        $this->actingAs($supervisor)->post(onPanel('carrier', 'supervisors'), ['name' => 'Kiran', 'email' => 'kiran@example.com'])->assertForbidden();
        $this->actingAs($supervisor)->put(onPanel('carrier', 'company'), ['contact_name' => 'X', 'contact_email' => 'x@example.com', 'contact_phone' => '0501112222'])->assertForbidden();

        $this->actingAs($owner)->post(onPanel('carrier', 'supervisors'), ['name' => 'Kiran', 'email' => 'kiran@example.com'])->assertRedirect();
        expect(User::query()->where('email', 'kiran@example.com')->exists())->toBeFalse();
        acceptTeamInvitation('carrier', 'kiran@example.com', 'kiran-pass-1');
        $kiran = User::query()->where('email', 'kiran@example.com')->sole();
        expect($kiran->memberships()->where('role', MembershipRole::CarrierSupervisor)->where('tenant_id', $carrier->id)->exists())->toBeTrue()
            ->and(Hash::check('kiran-pass-1', $kiran->password))->toBeTrue();

        $this->actingAs($owner)->put(onPanel('carrier', 'company'), ['contact_name' => 'Rania', 'contact_email' => 'ops@fastlane.ae', 'contact_phone' => '0501112222', 'coverage' => 'Dubai, Sharjah', 'fleet_size' => 42])->assertRedirect();
        expect($carrier->refresh()->details['coverage'])->toBe(['Dubai', 'Sharjah'])->and($carrier->contact_phone)->toBe('+971501112222');
    });

    it('shows claims with a carrier response and refuses another company or country', function () {
        [$owner, $carrier] = shippingCompany();
        $parcel = readyParcel();
        $claim = CarrierClaim::query()->create(['number' => 'CL-1', 'carrier_id' => $carrier->id, 'sub_order_id' => $parcel->id, 'type' => 'damaged', 'description' => 'Box crushed', 'currency' => 'AED', 'claimed_amount' => '50.00', 'status' => 'submitted', 'raised_by_type' => 'admin']);

        $this->actingAs($owner)->get(onPanel('carrier', 'claims'))->assertOk()->assertSee('CL-1')->assertSee('Box crushed');
        $this->actingAs($owner)->post(onPanel('carrier', "claims/{$claim->id}/respond"), ['response' => 'Photo at pickup shows the box intact.'])->assertRedirect();
        expect($claim->refresh()->carrier_response)->toBe('Photo at pickup shows the box intact.')->and($claim->carrier_responded_at)->not->toBeNull();

        [$rivalOwner] = shippingCompany('rival');
        $this->actingAs($rivalOwner)->post(onPanel('carrier', "claims/{$claim->id}/respond"), ['response' => 'Not ours at all'])->assertNotFound();

        $omanOwner = shippingCompany('oman', Country::query()->where('code', 'omn')->sole())[0];
        $this->actingAs($omanOwner)->get(onPanel('carrier', 'claims'))->assertOk()->assertDontSee('CL-1');

        $noCompany = User::factory()->create();
        $noCompany->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id]);
        $this->actingAs($noCompany)->get(onPanel('carrier'))->assertOk();
        $this->actingAs($noCompany)->get(onPanel('carrier', 'claims'))->assertForbidden();
    });

    it('lets admin hand a ready parcel to a shipping company from dispatch', function () {
        [, $carrier] = shippingCompany();
        $parcel = readyParcel();
        $admin = adminAccount('uae', 'operations');

        $this->actingAs($admin)->get(onPanel('admin', 'delivery/assignments'))->assertOk()->assertSee('Hand over');
        $this->actingAs($admin)->post(onPanel('admin', "delivery/sub-orders/{$parcel->id}/allocate"), ['carrier_id' => $carrier->id])->assertRedirect();
        expect(Shipment::query()->where('sub_order_id', $parcel->id)->value('carrier_id'))->toBe($carrier->id);
        $this->actingAs($admin)->get(onPanel('admin', 'delivery/assignments'))->assertSee('With Fastlane Couriers');
    });
});

describe('delivery agent pages', function () {
    it('shows earnings at the country pay rate, profile and documents', function () {
        $agent = companyAgent(null);
        $user = User::query()->findOrFail($agent->user_id);
        $parcel = readyParcel();
        $assignment = $this->dispatch->assign($parcel, $agent, ActorType::Admin, 1);
        $assignment->forceFill(['status' => AssignmentStatus::Delivered, 'delivered_at' => now()])->save();

        $this->actingAs($user)->get(onPanel('delivery', 'earnings'))->assertOk()->assertSee('Pay rates are not set');
        app(SettingsService::class)->set('delivery.pay_per_stop', '6.00', $this->country);
        $this->actingAs($user)->get(onPanel('delivery', 'earnings'))->assertOk()->assertSee('AED 6.00', false);

        $this->actingAs($user)->put(onPanel('delivery', 'profile'), ['phone' => '0509998888', 'vehicle_type' => 'bike', 'vehicle_plate' => 'DXB-1'])->assertRedirect();
        expect($agent->refresh()->vehicle_plate)->toBe('DXB-1')->and($agent->phone)->toBe('+971509998888');
        $this->actingAs($user)->get(onPanel('delivery', 'profile'))->assertOk()->assertSee('DXB-1');

        $type = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'driving_licence', 'name_en' => 'Driving licence', 'name_ar' => 'رخصة', 'business_type' => 'delivery_agent', 'requirement' => 'mandatory']);
        $this->actingAs($user)->get(onPanel('delivery', 'documents'))->assertOk()->assertSee('Driving licence');
        $this->actingAs($user)->post(onPanel('delivery', 'documents'), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')])->assertRedirect();
        expect(Document::query()->sole())->owner_type->toBe('delivery_agent')->owner_id->toBe($agent->id);
    });
});

describe('remaining partner pages', function () {
    it('opens store settings, the creator profile and travel reports with their navigation links', function () {
        [$seller, $vendor] = sellerAccount('uae', ['business_details' => ['store' => ['legal_name' => 'SoundMax Trading LLC', 'cr_number' => 'DED-1']]]);
        $this->actingAs($seller)->get(onPanel('partner', 'store'))->assertOk()->assertSee('SoundMax Trading LLC')->assertSee(onPanel('partner', 'store'), false);
        $this->actingAs($seller)->put(onPanel('partner', 'store'), ['contact_phone' => '0501234567', 'description' => 'Audio gear'])->assertRedirect();
        expect($vendor->refresh()->contact_phone)->toBe('+971501234567')->and($vendor->business_details['store']['description'])->toBe('Audio gear')
            ->and($vendor->business_details['store']['cr_number'])->toBe('DED-1');

        [$creator, $marketer] = marketerAccount(['influencer']);
        $this->actingAs($creator)->get(onPanel('marketer', 'profile'))->assertOk()->assertSee($marketer->display_name);
        $this->actingAs($creator)->put(onPanel('marketer', 'profile'), ['display_name' => 'Omar Tech', 'whatsapp_phone' => '0501234567', 'socials' => ['instagram' => '@omar.tech', 'myspace' => 'nope']])->assertRedirect();
        expect($marketer->refresh()->display_name)->toBe('Omar Tech')->and($marketer->socials)->toEqual([['network' => 'instagram', 'handle' => '@omar.tech']]);

        $owner = User::factory()->create();
        $agency = Vendor::factory()->create(['type' => 'travel', 'owner_user_id' => $owner->id]);
        $owner->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => $this->country->id, 'tenant_type' => 'vendor', 'tenant_id' => $agency->id]);
        $this->actingAs($owner)->get(onPanel('travel-agency', 'reports'))->assertOk()->assertSee('Booking reports')->assertSee('No confirmed bookings yet.');
        expect(TravelBooking::query()->count())->toBe(0);
    });
});
