<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\BusinessCalendar;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Enums\ClaimType;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Events\DeliveryDispatchUpdated;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\CarrierClaims;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\Scorecards;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Settings\SettingsService;
use App\Domains\Shipping\Models\ShippingZone;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
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
    $this->steps = app(AgentAssignments::class);
});

/**
 * An active delivery agent in the current country (membership + profile).
 *
 * @param  array<string, mixed>  $attributes
 */
function deliveryAgent(?Carrier $carrier = null, array $attributes = [], string $status = 'active'): DeliveryAgent
{
    $user = User::factory()->create(['password' => 'agent-password']);
    $country = app(CountryContext::class)->current();
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $country->id, 'tenant_type' => $carrier ? Carrier::TENANT : null, 'tenant_id' => $carrier?->id]);

    return DeliveryAgent::query()->create(['user_id' => $user->id, 'carrier_id' => $carrier?->id, 'name' => $user->name, 'status' => $status] + $attributes);
}

function carrier(string $code = 'swift'): Carrier
{
    return Carrier::query()->create(['code' => $code, 'name' => ucfirst($code).' Couriers', 'status' => 'active']);
}

/**
 * A packed COD sub-order whose order belongs to a real customer.
 */
function packedParcel(SubOrderStatus $status = SubOrderStatus::Packed, string $method = 'cod'): SubOrder
{
    $customer = User::factory()->create(['preferred_locale' => 'en']);
    $subOrder = subOrderFor(Vendor::factory()->create(), $status);
    $subOrder->order->forceFill(['customer_user_id' => $customer->id, 'payment_method' => $method])->save();

    return $subOrder->refresh();
}

function agentToken(DeliveryAgent $agent): string
{
    return User::query()->findOrFail($agent->user_id)->createToken('phone', ['delivery'])->plainTextToken;
}

it('assigns a packed parcel with a 4-digit code, history and an ops broadcast', function () {
    Event::fake([DeliveryDispatchUpdated::class]);
    $agent = deliveryAgent();
    $subOrder = packedParcel();

    $assignment = $this->dispatch->assign($subOrder, $agent, ActorType::Admin, 7);

    expect($assignment->status)->toBe(AssignmentStatus::Assigned)
        ->and($assignment->delivery_code)->toMatch('/^\d{4}$/')
        ->and($assignment->number)->toBe('DA-'.$subOrder->number.'-'.$assignment->id)
        ->and($assignment->cod_amount)->not->toBeNull()
        ->and($assignment->sla_due_at)->not->toBeNull()
        ->and($assignment->shipment->status)->toBe(ShipmentStatus::LabelCreated)
        ->and(StateTransition::query()->where('subject_type', 'delivery_assignment')->where('subject_id', $assignment->id)->first())
        ->actor_type->toBe('admin')->actor_user_id->toBe(7)->to_status->toBe('assigned');

    Event::assertDispatched(DeliveryDispatchUpdated::class, function (DeliveryDispatchUpdated $event): bool {
        return $event->event === 'assigned'
            && $event->broadcastOn()[0]->name === 'private-country.uae.ops'
            && ! array_key_exists('delivery_code', $event->broadcastWith());
    });
});

it('guards assignment: order status, agent status, zone, carrier and one open attempt', function () {
    $agent = deliveryAgent();

    expect(fn () => $this->dispatch->assign(packedParcel(SubOrderStatus::Confirmed), $agent, ActorType::Admin))
        ->toThrow(TransitionRefused::class, 'Only packed');

    expect(fn () => $this->dispatch->assign(packedParcel(), deliveryAgent(status: 'pending_documents'), ActorType::Admin))
        ->toThrow(TransitionRefused::class, 'not active');

    $zone = ShippingZone::factory()->create();
    $other = ShippingZone::factory()->create();
    $parcel = packedParcel();
    $parcel->forceFill(['shipping_zone_id' => $zone->id])->save();
    expect(fn () => $this->dispatch->assign($parcel, deliveryAgent(attributes: ['zone_ids' => [$other->id]]), ActorType::Admin))
        ->toThrow(TransitionRefused::class, 'zone');

    $swift = carrier();
    expect(fn () => $this->dispatch->assign(packedParcel(), $agent, ActorType::Carrier, 3, $swift))
        ->toThrow(TransitionRefused::class, 'your company');

    $parcel = packedParcel();
    $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    expect(fn () => $this->dispatch->assign($parcel, deliveryAgent(), ActorType::Admin))
        ->toThrow(TransitionRefused::class, 'already has an agent');
});

it('runs the code-match delivery through the agent API and records the COD cash against the agent', function () {
    Event::fake([DeliveryDispatchUpdated::class]);
    $agent = deliveryAgent();
    [$order] = placeOrderVia('cod');
    $parcel = $order->subOrders()->firstOrFail();
    $parcel->forceFill(['status' => SubOrderStatus::Packed])->save();
    $assignment = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    Notification::assertSentTo(User::find($agent->user_id), EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'delivery.assignment');

    $login = $this->postJson('/api/v1/uae-en/delivery/auth/login', ['email' => User::find($agent->user_id)->email, 'password' => 'agent-password', 'device_name' => 'Pixel'])
        ->assertOk()->json('data');
    app('auth')->forgetGuards();
    $token = $login['token'];

    $this->withToken($token)->getJson('/api/v1/uae-en/delivery/assignments')->assertOk()
        ->assertJsonPath('data.0.number', $assignment->number)
        ->assertJsonPath('data.0.delivery_code', $assignment->delivery_code)
        ->assertJsonPath('data.0.customer.name', 'Sara Ahmed');

    $base = "/api/v1/uae-en/delivery/assignments/{$assignment->number}";
    $this->withToken($token)->postJson("{$base}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->withToken($token)->postJson("{$base}/start")->assertOk()->assertJsonPath('data.status', 'picked_up')->assertJsonPath('data.proof.required', true);

    expect($parcel->refresh()->status)->toBe(SubOrderStatus::OutForDelivery)
        ->and(Shipment::query()->where('sub_order_id', $parcel->id)->value('attempts'))->toBe(1);
    Notification::assertSentTo(User::find($parcel->order->customer_user_id), EventEmail::class,
        fn (EventEmail $mail): bool => $mail->event === 'delivery.out_for_delivery' && $mail->variables['delivery_code'] === $assignment->delivery_code);

    $this->withToken($token)->postJson("{$base}/arrive")->assertOk()->assertJsonPath('data.arrived_at', fn ($value) => $value !== null);
    $this->withToken($token)->postJson("{$base}/confirm", ['codes_match' => false])->assertUnprocessable();
    $this->withToken($token)->postJson("{$base}/confirm", ['codes_match' => true])->assertUnprocessable()
        ->assertJsonPath('error.code', 'transition_refused');

    $this->withToken($token)->post("{$base}/proof", ['photo' => UploadedFile::fake()->image('door.jpg')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.proof.has_photo', true);
    $this->withToken($token)->postJson("{$base}/confirm", ['codes_match' => true])->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.delivery_code', null)
        ->assertJsonPath('data.customer', null);

    $assignment->refresh();
    Storage::disk('local')->assertExists($assignment->proof_photo_path);
    expect($parcel->refresh()->status)->toBe(SubOrderStatus::Delivered)
        ->and($assignment->shipment->status)->toBe(ShipmentStatus::Delivered)
        ->and(CodCollection::query()->where('sub_order_id', $parcel->id)->value('agent_user_id'))->toBe($agent->user_id)
        ->and($parcel->order->payments()->latest('id')->first()->status)->toBe(PaymentStatus::Captured)
        ->and(ledgerBalance(AccountRef::codReceivable($agent->user_id)))->toBe((string) app(MoneyFactory::class)->of((string) $assignment->cod_amount, 'AED')->getAmount())
        ->and($parcel->statusHistory()->get()->last()->actor_type)->toBe(ActorType::Agent);
    assertLedgerBalanced();
    Event::assertDispatched(DeliveryDispatchUpdated::class, fn ($event): bool => $event->event === 'delivered');
});

it('accepts a drawn signature as proof when the country asks for signatures', function () {
    app(SettingsService::class)->set('delivery.proof_type', 'signature', $this->country);
    $assignment = $this->dispatch->assign(packedParcel(), deliveryAgent(), ActorType::Admin);
    $this->steps->accept($assignment, 1);
    $this->steps->start($assignment, 1);
    $this->steps->attachProof($assignment, UploadedFile::fake()->image('photo.jpg'));

    expect(fn () => $this->steps->confirmDelivery($assignment->refresh(), 1, true))->toThrow(TransitionRefused::class, 'signature');

    $png = base64_encode(UploadedFile::fake()->image('sig.png')->getContent());
    $this->steps->attachProof($assignment, signatureData: 'data:image/png;base64,'.$png);
    expect(fn () => $this->steps->attachProof($assignment, signatureData: 'data:image/png;base64,'.base64_encode('not an image')))->toThrow(TransitionRefused::class);

    $this->steps->confirmDelivery($assignment->refresh(), 1, true);
    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Delivered);
});

it('does not ask prepaid parcels below the threshold for proof, but does above it', function () {
    $assignment = $this->dispatch->assign(packedParcel(method: 'card'), deliveryAgent(), ActorType::Admin);
    expect($assignment->cod_amount)->toBeNull()->and($this->steps->needsProof($assignment))->toBeFalse();

    app(SettingsService::class)->set('delivery.proof_required_above', '50', $this->country);
    expect($this->steps->needsProof($assignment))->toBeTrue();
});

it('keeps agents to their own assignments and their own country', function () {
    $mine = deliveryAgent();
    $theirs = $this->dispatch->assign(packedParcel(), deliveryAgent(), ActorType::Admin);
    $token = agentToken($mine);

    $this->withToken($token)->getJson("/api/v1/uae-en/delivery/assignments/{$theirs->number}")->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/uae-en/delivery/assignments/{$theirs->number}/accept")->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/ksa-en/delivery/me')->assertForbidden()->assertJsonPath('error.code', 'not_a_delivery_agent');

    app('auth')->forgetGuards();
    [, $customerToken] = customerWithToken();
    $this->withToken($customerToken)->getJson('/api/v1/uae-en/delivery/assignments')->assertForbidden();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/uae-en/delivery/auth/login', ['email' => User::find($mine->user_id)->email, 'password' => 'wrong', 'device_name' => 'x'])->assertUnprocessable();
    $this->postJson('/api/v1/ksa-en/delivery/auth/login', ['email' => User::find($mine->user_id)->email, 'password' => 'agent-password', 'device_name' => 'x'])->assertForbidden();

    $this->actAsCountry('uae');
    $mine->forceFill(['status' => AgentStatus::Suspended])->save();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/uae-en/delivery/me')->assertForbidden();
});

it('shows the customer their copy of the open attempt code on the order', function () {
    [$order, $customer, $token] = placeOrderVia('cod');
    $subOrder = $order->subOrders()->firstOrFail();
    $subOrder->forceFill(['status' => SubOrderStatus::Packed])->save();

    $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertOk()->assertJsonPath('data.shipments.0.delivery_code', null);

    $assignment = $this->dispatch->assign($subOrder, deliveryAgent(), ActorType::Admin);
    $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertJsonPath('data.shipments.0.delivery_code', $assignment->delivery_code);
});

it('fails with a listed reason, reschedules to a business day and redelivers with a new code', function () {
    $agent = deliveryAgent();
    $parcel = packedParcel();
    $first = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    $this->steps->accept($first, $agent->user_id);
    $this->steps->start($first, $agent->user_id);

    expect(fn () => $this->steps->fail($first, $agent->user_id, 'dog_ate_it'))->toThrow(TransitionRefused::class);
    $this->steps->fail($first, $agent->user_id, 'not_home', 'Nobody answered');

    $shipment = $first->shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->last_failure_reason)->toBe('not_home')
        ->and($parcel->refresh()->status)->toBe(SubOrderStatus::OutForDelivery);
    Notification::assertSentTo(User::find($parcel->order->customer_user_id), EventEmail::class, fn (EventEmail $mail): bool => $mail->event === 'delivery.failed');

    expect(fn () => $this->dispatch->assign($parcel, $agent, ActorType::Admin))->toThrow(TransitionRefused::class, 'cannot be assigned');

    $calendar = app(BusinessCalendar::class);
    $today = CarbonImmutable::now($this->country->timezone);
    $weekendDay = $today->next(CarbonImmutable::SATURDAY);
    expect(fn () => $this->dispatch->reschedule($shipment, $weekendDay->toDateString(), ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'weekend');
    expect(fn () => $this->dispatch->reschedule($shipment, $today->subDay()->toDateString(), ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'Pick a day');

    $day = $calendar->addBusinessDays($this->country, $today, 1);
    $this->dispatch->reschedule($shipment, $day->toDateString(), ActorType::Admin, 1);
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Rescheduled)
        ->and($parcel->refresh()->estimated_delivery_by->toDateString())->toBe($day->toDateString());

    $second = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    expect($second->attempt_no)->toBe(2)->and($second->delivery_code)->toMatch('/^\d{4}$/');
    $this->steps->accept($second, $agent->user_id);
    $this->steps->start($second, $agent->user_id);
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::OutForDelivery)->and($shipment->attempts)->toBe(2);
});

it('returns to origin after the last attempt and cancels the order when it is back', function () {
    app(SettingsService::class)->set('delivery.max_attempts', 1, $this->country);
    $agent = deliveryAgent();
    $parcel = packedParcel();
    $listing = $parcel->items->first()->listing_id;
    $stockBefore = DB_stock($listing);
    $assignment = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    $this->steps->accept($assignment, $agent->user_id);
    $this->steps->start($assignment, $agent->user_id);
    $this->steps->fail($assignment, $agent->user_id, 'unreachable');

    $shipment = $assignment->shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::ReturningToOrigin)
        ->and(fn () => $this->dispatch->reschedule($shipment, now()->toDateString(), ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'No attempts');

    $this->dispatch->confirmReturned($shipment, ActorType::Admin, 9);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Returned)
        ->and($shipment->returned_at)->not->toBeNull()
        ->and($parcel->refresh()->status)->toBe(SubOrderStatus::Cancelled)
        ->and(DB_stock($listing))->toBe($stockBefore + 1)
        ->and(StateTransition::query()->where('subject_type', 'shipment')->where('subject_id', $shipment->id)->pluck('to_status')->all())
        ->toBe(['label_created', 'picked_up', 'out_for_delivery', 'failed', 'returning_to_origin', 'returned']);
});

it('sends a refused parcel straight back to origin', function () {
    $agent = deliveryAgent();
    $assignment = $this->dispatch->assign(packedParcel(), $agent, ActorType::Admin);
    $this->steps->accept($assignment, $agent->user_id);
    $this->steps->start($assignment, $agent->user_id);
    $this->steps->fail($assignment, $agent->user_id, 'refused');

    expect($assignment->shipment->refresh()->status)->toBe(ShipmentStatus::ReturningToOrigin);
});

it('lets agents decline, and reassigns open work with a new code', function () {
    $agent = deliveryAgent();
    $parcel = packedParcel();
    $assignment = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    $this->steps->decline($assignment, $agent->user_id, 'Bike broke down');
    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Declined)->and($assignment->decline_reason)->toBe('Bike broke down');

    $again = $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    $other = deliveryAgent();
    $new = $this->dispatch->reassign($again, $other, ActorType::Admin, 1, null, 'Closer agent');

    expect($again->refresh()->status)->toBe(AssignmentStatus::Reassigned)
        ->and($new->delivery_agent_id)->toBe($other->id)
        ->and($new->status)->toBe(AssignmentStatus::Assigned)
        ->and($new->id)->not->toBe($again->id);

    $this->steps->accept($new, $other->user_id);
    $this->steps->start($new, $other->user_id);
    expect(fn () => $this->dispatch->reassign($new->refresh(), $agent, ActorType::Admin))->toThrow(TransitionRefused::class)
        ->and(fn () => $this->steps->accept($new->refresh(), $other->user_id))->toThrow(TransitionRefused::class);
});

it('auto-assigns to the least busy agent in the zone and can be switched off', function () {
    $busy = deliveryAgent();
    $free = deliveryAgent();
    deliveryAgent(status: 'unavailable');
    $this->dispatch->assign(packedParcel(), $busy, ActorType::Admin);

    $assignment = $this->dispatch->autoAssign(packedParcel());
    expect($assignment->delivery_agent_id)->toBe($free->id)->and($assignment->assigned_via)->toBe('auto');

    app(SettingsService::class)->set('delivery.auto_assign', 'off', $this->country);
    expect(fn () => $this->dispatch->autoAssign(packedParcel()))->toThrow(TransitionRefused::class, 'switched off');
});

it('lets agents toggle availability but not while carrying parcels', function () {
    $agent = deliveryAgent();
    $token = agentToken($agent);
    $this->withToken($token)->postJson('/api/v1/uae-en/delivery/me/availability', ['available' => false])->assertOk()->assertJsonPath('data.available', false);
    $this->withToken($token)->postJson('/api/v1/uae-en/delivery/me/availability', ['available' => true])->assertOk()->assertJsonPath('data.available', true);

    $assignment = $this->dispatch->assign(packedParcel(), $agent->refresh(), ActorType::Admin);
    $this->steps->accept($assignment, $agent->user_id);
    $this->steps->start($assignment, $agent->user_id);
    $this->withToken($token)->postJson('/api/v1/uae-en/delivery/me/availability', ['available' => false])->assertUnprocessable();
});

it('flags SLA breaches once from the scheduled job', function () {
    Event::fake([DeliveryDispatchUpdated::class]);
    $assignment = $this->dispatch->assign(packedParcel(), deliveryAgent(), ActorType::Admin);
    $this->travel(5)->hours();

    $this->artisan('nawy:delivery:check-sla')->assertSuccessful();
    $this->artisan('nawy:delivery:check-sla')->assertSuccessful();

    expect($assignment->refresh()->sla_breached_at)->not->toBeNull();
    Event::assertDispatchedTimes(DeliveryDispatchUpdated::class, 2);
});

it('runs carrier claims to compensation for the seller and for the customer', function () {
    $swift = carrier();
    $agent = deliveryAgent($swift);
    $parcel = packedParcel();
    $claims = app(CarrierClaims::class);
    $money = app(MoneyFactory::class);

    expect(fn () => $claims->open($parcel, ClaimType::Lost, 'Lost', $money->of('10', 'AED'), 'vendor', ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'not handled by a carrier');

    $this->dispatch->assign($parcel, $agent, ActorType::Admin);
    expect(fn () => $claims->open($parcel, ClaimType::Lost, 'Lost', $money->of('1000', 'AED'), 'vendor', ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'at most the order value');

    $claim = $claims->open($parcel, ClaimType::Damaged, 'Screen cracked', $money->of('80', 'AED'), 'vendor', ActorType::Admin, 1);
    expect($claim->carrier_id)->toBe($swift->id)->and($claim->status)->toBe(ClaimStatus::Submitted);
    expect(fn () => $claims->approve($claim, $money->of('80', 'AED'), 1))->toThrow(TransitionRefused::class);

    $claims->review($claim, 1);
    expect(fn () => $claims->approve($claim, $money->of('90', 'AED'), 1))->toThrow(TransitionRefused::class, 'at most the claimed');
    $claims->approve($claim, $money->of('60', 'AED'), 1, 'Partial');
    $claims->compensate($claim, ActorType::Admin, 1);

    expect($claim->refresh()->status)->toBe(ClaimStatus::Compensated)
        ->and(ledgerBalance(AccountRef::carrierReceivable($swift->id)))->toBe('60.00')
        ->and(ledgerBalance(AccountRef::vendor($parcel->vendor_id, WalletBucket::Spendable)))->toBe('60.00');

    $toCustomer = $claims->open($parcel, ClaimType::Lost, 'Gone', $money->of('20', 'AED'), 'customer', ActorType::Admin, 1);
    $claims->review($toCustomer, 1);
    $claims->approve($toCustomer, $money->of('20', 'AED'), 1);
    $claims->compensate($toCustomer, ActorType::Admin, 1);
    expect(ledgerBalance(AccountRef::customerWallet($parcel->order->customer_user_id)))->toBe('20.00');

    $rejected = $claims->open($parcel, ClaimType::Lost, 'Gone', $money->of('5', 'AED'), 'vendor', ActorType::Admin, 1);
    $claims->review($rejected, 1);
    $claims->reject($rejected, 1, 'Signed for by the customer');
    expect(fn () => $claims->compensate($rejected, ActorType::Admin, 1))->toThrow(TransitionRefused::class);
    assertLedgerBalanced();
});

it('scores agents and carriers on success, punctuality and upheld claims', function () {
    $swift = carrier();
    $agent = deliveryAgent($swift);
    foreach (['deliver', 'fail'] as $outcome) {
        $assignment = $this->dispatch->assign(packedParcel(method: 'card'), $agent, ActorType::Admin);
        $this->steps->accept($assignment, $agent->user_id);
        $this->steps->start($assignment, $agent->user_id);
        $outcome === 'deliver' ? $this->steps->confirmDelivery($assignment, $agent->user_id, true) : $this->steps->fail($assignment, $agent->user_id, 'not_home');
    }

    $scorecards = app(Scorecards::class);
    $row = $scorecards->agents(now()->subDay(), now()->addMinute())->firstWhere('agent.id', $agent->id);
    expect($row)->attempts->toBe(2)->delivered->toBe(1)->failed->toBe(1)->success_rate->toBe(50.0)->on_time_rate->toBe(100.0)->score->toBe(70.0);

    $carrierRow = $scorecards->carriers(now()->subDay(), now()->addMinute())->firstWhere('carrier.id', $swift->id);
    expect($carrierRow['score'])->toBe(70.0)->and($carrierRow['claims'])->toBe(0);
});

it('lets operations dispatch from the admin panel and keeps other roles out', function () {
    $ops = adminAccount('uae', 'operations');
    $agent = deliveryAgent();
    $parcel = packedParcel();

    $this->actingAs($ops)->get(onPanel('admin', 'delivery/assignments'))->assertOk()->assertSee($parcel->number);
    $this->actingAs($ops)->post(onPanel('admin', "delivery/sub-orders/{$parcel->id}/assign"), ['agent_id' => $agent->id])->assertRedirect();
    $assignment = DeliveryAssignment::query()->where('sub_order_id', $parcel->id)->sole();
    $this->actingAs($ops)->get(onPanel('admin', "delivery/assignments/{$assignment->id}"))->assertOk()->assertSee($assignment->delivery_code);
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/scorecard'))->assertOk();
    $this->actingAs($ops)->get(onPanel('admin', 'delivery/carriers'))->assertOk();

    $support = adminAccount('uae', 'support');
    $this->actingAs($support)->get(onPanel('admin', 'delivery/assignments'))->assertOk();
    $this->actingAs($support)->post(onPanel('admin', "delivery/sub-orders/{$parcel->id}/assign"), ['auto' => 1])->assertForbidden();
    $this->actingAs($support)->get(onPanel('admin', 'delivery/agents'))->assertForbidden();

    $finance = adminAccount('uae', 'finance');
    $this->actingAs($finance)->get(onPanel('admin', 'delivery/assignments'))->assertForbidden();
    $this->actingAs($finance)->get(onPanel('admin', 'delivery/claims'))->assertOk();

    $ksaOps = adminAccount('ksa', 'operations');
    $this->actingAs($ksaOps)->get(onPanel('admin', "delivery/assignments/{$assignment->id}"))->assertNotFound();
});

it('answers an unknown parcel number on a carrier claim with a form error, not a 404 (browser test BT-ADM-002)', function () {
    $this->actingAs(adminAccount())
        ->from(onPanel('admin', 'delivery/claims'))
        ->post(onPanel('admin', 'delivery/claims'), ['sub_order_number' => 'NW-AE-NOPE-1', 'type' => 'lost', 'description' => 'Lost in transit', 'amount' => '10', 'compensate_to' => 'vendor'])
        ->assertRedirect(onPanel('admin', 'delivery/claims'))
        ->assertSessionHasErrors('sub_order_number');
});

it('invites agents from the admin panel; the agent exists once the invitation is accepted (wave 2)', function () {
    $ops = adminAccount('uae', 'operations');
    $swift = carrier();

    $this->actingAs($ops)->post(onPanel('admin', 'delivery/agents'), ['email' => 'Rider@Example.com', 'name' => 'Omar Rider', 'carrier_id' => $swift->id, 'vehicle_type' => 'bike'])->assertRedirect();
    expect(DeliveryAgent::query()->count())->toBe(0);

    $acceptUrl = null;
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification) use (&$acceptUrl): bool {
        $acceptUrl = $notification->acceptUrl;

        return true;
    });
    auth()->logout();
    $this->post($acceptUrl, ['password' => 'rider-pass-1', 'password_confirmation' => 'rider-pass-1'])->assertRedirect();

    $agent = DeliveryAgent::query()->sole();
    $user = User::query()->where('email', 'rider@example.com')->sole();
    expect($agent->status)->toBe(AgentStatus::PendingDocuments)
        ->and($agent->carrier_id)->toBe($swift->id)
        ->and($user->hasMembership(MembershipRole::DeliveryAgent, $this->country))->toBeTrue();

    $this->actingAs($ops)->post(onPanel('admin', "delivery/agents/{$agent->id}/status"), ['to' => 'active'])->assertRedirect();
    expect($agent->refresh()->status)->toBe(AgentStatus::Active);
    $this->actingAs($ops)->post(onPanel('admin', 'delivery/agents'), ['email' => 'rider@example.com', 'name' => 'Again'])->assertSessionHasErrors('action');
});

it('serves the delivery panel to the agent with their code and steps only', function () {
    $agent = deliveryAgent();
    $user = User::query()->findOrFail($agent->user_id);
    $assignment = $this->dispatch->assign(packedParcel(), $agent, ActorType::Admin);
    $theirs = $this->dispatch->assign(packedParcel(), deliveryAgent(), ActorType::Admin);

    $this->actingAs($user)->get(onPanel('delivery'))->assertOk()->assertSee('My day')->assertSee($assignment->number);
    $this->actingAs($user)->get(onPanel('delivery', "assignments/{$assignment->number}"))->assertOk()->assertSee($assignment->delivery_code);
    $this->actingAs($user)->get(onPanel('delivery', "assignments/{$theirs->number}"))->assertNotFound();

    $this->actingAs($user)->post(onPanel('delivery', "assignments/{$assignment->number}/accept"))->assertRedirect();
    $this->actingAs($user)->post(onPanel('delivery', "assignments/{$assignment->number}/start"))->assertRedirect();
    $this->actingAs($user)->post(onPanel('delivery', "assignments/{$assignment->number}/confirm"), ['codes_match' => 1, 'photo' => UploadedFile::fake()->image('door.jpg')])->assertRedirect();
    expect($assignment->refresh()->status)->toBe(AssignmentStatus::Delivered);

    $this->actingAs($user)->get(onPanel('delivery', 'cod'))->assertOk();
    $this->actingAs($user)->post(onPanel('delivery', 'availability'), ['available' => 0])->assertRedirect();
    expect($agent->refresh()->status)->toBe(AgentStatus::Unavailable);
});

/**
 * Units on hand of a listing (current country).
 */
function DB_stock(int $listingId): int
{
    return app(InventoryLedger::class)->available(Listing::query()->findOrFail($listingId));
}
