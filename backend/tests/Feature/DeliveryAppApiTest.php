<?php

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Notification::fake();
    $user = User::factory()->create(['email' => 'rashid@example.com']);
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $this->country->id]);
    $this->agent = DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => 'Rashid', 'status' => 'active', 'phone' => '+971501112222']);
    $this->token = $user->createToken('phone', ['delivery'])->plainTextToken;
});

/**
 * A packed COD parcel assigned to the agent and finished as `$status` at `$at`.
 */
function appFinishedStop(DeliveryAgent $agent, AssignmentStatus $status, mixed $at): DeliveryAssignment
{
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id])->save();
    $assignment = app(DeliveryDispatch::class)->assign($subOrder->refresh(), $agent, ActorType::Admin, 1);
    $assignment->forceFill(['status' => $status, 'delivered_at' => $status === AssignmentStatus::Delivered ? $at : null, 'updated_at' => $at])->saveQuietly();

    return $assignment;
}

it('adds phone and email to the profile and updates phone and vehicle with the panel rules', function () {
    $this->withToken($this->token)->getJson('/api/v1/uae-en/delivery/me')->assertOk()
        ->assertJsonPath('data.email', 'rashid@example.com')->assertJsonPath('data.phone', '+971501112222');

    $this->withToken($this->token)->patchJson('/api/v1/uae-en/delivery/me', ['phone' => '0509998888', 'vehicle_type' => 'van', 'vehicle_plate' => 'DXB-1'])->assertOk()
        ->assertJsonPath('data.phone', '+971509998888')->assertJsonPath('data.vehicle', ['type' => 'van', 'plate' => 'DXB-1']);
    $this->withToken($this->token)->patchJson('/api/v1/uae-en/delivery/me', ['phone' => '12', 'vehicle_type' => 'rocket'])->assertUnprocessable()
        ->assertJsonValidationErrors(['phone', 'vehicle_type'], 'error.fields');
    expect($this->agent->refresh()->vehicle_type)->toBe('van');
});

it('shows 7 days of earnings at the country pay rates', function () {
    appFinishedStop($this->agent, AssignmentStatus::Delivered, now());
    appFinishedStop($this->agent, AssignmentStatus::Delivered, now());
    appFinishedStop($this->agent, AssignmentStatus::Failed, now()->subDays(2));
    appFinishedStop($this->agent, AssignmentStatus::Delivered, now()->subDays(9));

    $this->withToken($this->token)->getJson('/api/v1/uae-en/delivery/me/earnings')->assertOk()
        ->assertJsonCount(7, 'data.days')
        ->assertJsonPath('data.days.6.date', now($this->country->timezone)->toDateString())
        ->assertJsonPath('data.days.6.delivered', 2)
        ->assertJsonPath('data.days.6.earned', null)
        ->assertJsonPath('data.total', null)->assertJsonPath('data.per_stop', null)
        ->assertJsonPath('data.stops', 2)->assertJsonPath('data.failed', 1);

    app(SettingsService::class)->set('delivery.pay_per_stop', '6.00', $this->country);
    app(SettingsService::class)->set('delivery.pay_per_failed_attempt', '1.50', $this->country);
    $this->withToken($this->token)->getJson('/api/v1/uae-en/delivery/me/earnings')->assertOk()
        ->assertJsonPath('data.days.6.earned', ['amount' => '12.00', 'currency' => 'AED'])
        ->assertJsonPath('data.days.4.failed', 1)
        ->assertJsonPath('data.days.4.earned', ['amount' => '1.50', 'currency' => 'AED'])
        ->assertJsonPath('data.total', ['amount' => '13.50', 'currency' => 'AED'])
        ->assertJsonPath('data.per_stop', ['amount' => '6.00', 'currency' => 'AED'])
        ->assertJsonPath('data.per_failed', ['amount' => '1.50', 'currency' => 'AED']);
});

it('shows only my own cash in hand and settlements', function () {
    $mine = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Delivered);
    $settled = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Delivered);
    $theirs = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Delivered);
    $settlement = CodSettlement::query()->create(['number' => 'COD-1', 'agent_user_id' => $this->agent->user_id, 'agent_key' => 'user:'.$this->agent->user_id, 'business_date' => now()->subDay()->toDateString(), 'currency' => 'AED', 'expected_amount' => '80.00', 'collections_count' => 1, 'status' => 'pending']);
    $collect = fn (SubOrder $subOrder, ?int $userId, ?int $settlementId = null, string $amount = '105.00') => CodCollection::query()->create([
        'sub_order_id' => $subOrder->id, 'order_id' => $subOrder->order_id, 'agent_user_id' => $userId, 'currency' => 'AED', 'amount' => $amount, 'collected_at' => now(), 'cod_settlement_id' => $settlementId,
    ]);
    $collection = $collect($mine, $this->agent->user_id);
    $collect($settled, $this->agent->user_id, $settlement->id, '80.00');
    $collect($theirs, $this->agent->user_id + 100);

    $this->withToken($this->token)->getJson('/api/v1/uae-en/delivery/me/cod')->assertOk()
        ->assertJsonPath('data.in_hand', ['amount' => '105.00', 'currency' => 'AED'])
        ->assertJsonCount(1, 'data.collections')
        ->assertJsonPath('data.collections.0.id', $collection->id)
        ->assertJsonPath('data.collections.0.order_number', $mine->order->number)
        ->assertJsonPath('data.collections.0.sub_order_number', $mine->number)
        ->assertJsonPath('data.settlements.0.id', $settlement->id)
        ->assertJsonPath('data.settlements.0.expected', ['amount' => '80.00', 'currency' => 'AED'])
        ->assertJsonPath('data.settlements.0.status', 'pending');
});

it('lists document requirements and my uploads', function () {
    $type = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'driving_licence', 'name_en' => 'Driving licence', 'name_ar' => 'رخصة', 'business_type' => 'delivery_agent', 'requirement' => 'mandatory']);
    DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'vehicle_photo', 'name_en' => 'Vehicle photo', 'name_ar' => 'صورة', 'business_type' => 'delivery_agent', 'requirement' => 'optional']);
    app(Documents::class)->upload($type, 'delivery_agent', $this->agent->id, UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'), $this->agent->user_id, null, now()->addYear()->toDateString());

    $this->withToken($this->token)->getJson('/api/v1/uae-en/delivery/me/documents')->assertOk()
        ->assertJsonPath('data.requirements', [
            ['id' => $type->id, 'code' => 'driving_licence', 'name' => 'Driving licence', 'mandatory' => true],
            ['id' => $type->id + 1, 'code' => 'vehicle_photo', 'name' => 'Vehicle photo', 'mandatory' => false],
        ])
        ->assertJsonPath('data.uploads.0.id', Document::query()->sole()->id)
        ->assertJsonPath('data.uploads.0.type', 'Driving licence')
        ->assertJsonPath('data.uploads.0.valid_until', now()->addYear()->toDateString());
});

it('serves the shared notification centre to an agent token and keeps other countries out', function () {
    $this->withToken($this->token)->getJson('/api/v1/uae-en/me/notifications')->assertOk()->assertJsonPath('meta.unread', 0);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/ksa-en/delivery/me/earnings')->assertForbidden()->assertJsonPath('error.code', 'not_a_delivery_agent');
});
