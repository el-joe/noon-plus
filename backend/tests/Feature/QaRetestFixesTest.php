<?php

use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Support\StockAvailability;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentAssignments;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\RequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
| QA re-test fixes (RT-001 … RT-013, docs/project/qa-retest.md). RT-003,
| RT-006 and RT-008 are covered by the updated wave 5a, partner
| application and wave 3 sign-in tests.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
    Storage::fake('local');
    Storage::fake('public');
    app(SettingsService::class)->set('delivery.proof_required_for_cod', false, $this->country);
});

/**
 * An agent with a delivery-app token and a COD parcel assigned, accepted
 * and picked up (out for delivery).
 *
 * @return array{0: DeliveryAssignment, 1: string, 2: DeliveryAgent}
 */
function rtParcelOutForDelivery(): array
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => test()->country->id]);
    $agent = DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => 'Rashid', 'status' => 'active']);
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id, 'payment_method' => 'cod'])->save();
    $assignment = app(DeliveryDispatch::class)->assign($subOrder->refresh(), $agent, ActorType::Admin, 1);
    app(AgentAssignments::class)->accept($assignment, $user->id);
    app(AgentAssignments::class)->start($assignment->refresh(), $user->id);

    return [$assignment->refresh(), $user->createToken('phone', ['delivery'])->plainTextToken, $agent];
}

/**
 * A sub-order cancelled the way legacy data was: the status column only,
 * so its assignment is still open (before the QA-005 close-on-cancel).
 */
function rtCancelLegacy(DeliveryAssignment $assignment): SubOrder
{
    $subOrder = $assignment->subOrder()->firstOrFail();
    SubOrder::query()->whereKey($subOrder->id)->update(['status' => SubOrderStatus::Cancelled->value]);
    $subOrder->statusHistory()->create(['from_status' => SubOrderStatus::OutForDelivery, 'to_status' => SubOrderStatus::Cancelled, 'actor_type' => ActorType::Admin, 'reason' => 'legacy']);

    return $subOrder->refresh();
}

describe('RT-001 / RT-011: delivery on a cancelled order', function () {
    it('refuses to confirm an open assignment whose sub-order was cancelled, reading the real status from the database', function () {
        [$assignment, $token] = rtParcelOutForDelivery();
        rtCancelLegacy($assignment);

        expect($assignment->refresh()->status)->toBe(AssignmentStatus::PickedUp)
            ->and($assignment->subOrder()->value('status'))->toBe(SubOrderStatus::Cancelled);

        $this->withToken($token)->postJson("/api/v1/uae-en/delivery/assignments/{$assignment->number}/confirm", ['codes_match' => true])
            ->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused')
            ->assertJsonPath('error.message', 'This order was cancelled. Return the parcel to the origin.');

        expect($assignment->refresh()->status)->toBe(AssignmentStatus::PickedUp)
            ->and(Shipment::query()->findOrFail($assignment->shipment_id)->status)->toBe(ShipmentStatus::OutForDelivery);
    });

    it('refuses to start an accepted assignment whose sub-order was cancelled', function () {
        $user = User::factory()->create();
        $user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $this->country->id]);
        $agent = DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => 'Rashid', 'status' => 'active']);
        $assignment = app(DeliveryDispatch::class)->assign(subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed), $agent, ActorType::Admin, 1);
        app(AgentAssignments::class)->accept($assignment, $user->id);
        SubOrder::query()->whereKey($assignment->sub_order_id)->update(['status' => SubOrderStatus::Cancelled->value]);
        $token = $user->createToken('phone', ['delivery'])->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/uae-en/delivery/assignments/{$assignment->number}/start")
            ->assertUnprocessable()->assertJsonPath('error.message', 'This order was cancelled. Return the parcel to the origin.');
        expect($assignment->refresh()->status)->toBe(AssignmentStatus::Accepted);
    });

    it('says the delivery was cancelled when the agent confirms with proof after an admin force-cancel', function () {
        [$assignment, $token] = rtParcelOutForDelivery();
        app(SubOrderStateMachine::class)->transition($assignment->subOrder()->firstOrFail(), SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Force cancel');

        $this->withToken($token)->postJson("/api/v1/uae-en/delivery/assignments/{$assignment->number}/confirm", [
            'codes_match' => true, 'signature_data' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nxx"),
        ])->assertUnprocessable()->assertJsonPath('error.message', 'This delivery was cancelled. Return the parcel to the origin.');

        expect($assignment->refresh()->signature_path)->toBeNull();
    });

    it('closes assignments left open on cancelled sub-orders and repairs one delivered after the cancel', function () {
        [$open] = rtParcelOutForDelivery();
        rtCancelLegacy($open);
        [$delivered] = rtParcelOutForDelivery();
        rtCancelLegacy($delivered);
        $this->travel(1)->minutes();
        DeliveryAssignment::query()->whereKey($delivered->id)->update(['status' => AssignmentStatus::Delivered->value, 'delivered_at' => now()]);
        Shipment::query()->whereKey($delivered->shipment_id)->update(['status' => ShipmentStatus::Delivered->value, 'delivered_at' => now()]);
        [$healthy] = rtParcelOutForDelivery();

        $this->artisan('nawy:delivery:close-stale-assignments', ['--repair-delivered' => true, '--dry-run' => true])->assertSuccessful();
        expect($open->refresh()->status)->toBe(AssignmentStatus::PickedUp);

        $this->artisan('nawy:delivery:close-stale-assignments', ['--repair-delivered' => true])->assertSuccessful()
            ->expectsOutputToContain($open->number);

        expect($open->refresh()->status)->toBe(AssignmentStatus::Cancelled)
            ->and(Shipment::query()->findOrFail($open->shipment_id)->status)->toBe(ShipmentStatus::ReturningToOrigin)
            ->and($delivered->refresh()->status)->toBe(AssignmentStatus::Cancelled)
            ->and($delivered->transitions()->pluck('reason')->filter()->implode(' '))->toContain('RT-001')
            ->and(Shipment::query()->findOrFail($delivered->shipment_id)->status)->toBe(ShipmentStatus::ReturningToOrigin)
            ->and($healthy->refresh()->status)->toBe(AssignmentStatus::PickedUp);
    });
});

describe('RT-002: guest holds per IP', function () {
    it('caps the units one IP holds of a listing across every guest cart it opens', function () {
        $listing = sellableListing('50.00', stock: 15);

        foreach (range(1, 5) as $attempt) {
            $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])->assertOk();
        }

        expect(Cart::query()->count())->toBe(5)
            ->and(app(StockAvailability::class)->availableFor(Listing::query()->findOrFail($listing->id)))->toBe(15 - (int) config('nawy.checkout.guest_hold_max_per_ip'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])->assertOk();
        expect(app(StockAvailability::class)->availableFor(Listing::query()->findOrFail($listing->id)))->toBe(15 - (int) config('nawy.checkout.guest_hold_max_per_ip') - 3);
    });

    it('limits how many guest carts one IP may open', function () {
        config(['nawy.checkout.guest_carts_per_hour' => 2]);
        $listing = sellableListing('50.00', stock: 15);

        $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk()->headers->get('X-Cart-Token');
        $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])
            ->assertStatus(429)->assertJsonPath('error.code', 'too_many_carts');

        // The cart the shopper already holds keeps working.
        $this->withHeader('X-Cart-Token', $token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])->assertOk();
        expect(Cart::query()->count())->toBe(2);
    });
});

it('RT-005: store facets list only that store and its own option values', function () {
    $store = Vendor::factory()->create(['slug' => 'rt-store']);
    $own = sellableListing('100.00', vendor: $store);
    $product = $own->variant->product;
    $own->variant->update(['option_values' => ['colour' => 'Black']]);
    $white = ProductVariant::factory()->create(['product_id' => $product->id, 'option_values' => ['colour' => 'White']]);
    sellableListing('90.00', attributes: ['product_variant_id' => $white->id]);
    sellableListing('80.00');

    $response = $this->getJson('/api/v1/uae-en/sellers/rt-store/products/facets')->assertOk()->assertJsonPath('meta.total', 1);

    expect(collect($response->json('data.sellers'))->pluck('slug')->all())->toBe(['rt-store'])
        ->and(collect($response->json('data.attributes'))->firstWhere('key', 'colour')['values'])->toBe([['value' => 'Black', 'count' => 1]]);
    expect(collect($this->getJson('/api/v1/uae-en/products/facets')->json('data.sellers')))->toHaveCount(3);
});

it('RT-007: an admin marks a classified category as needing a contract, which then gates posting until signed', function () {
    $category = ClassifiedCategory::query()->create(['slug' => 'rt-boats', 'name_en' => 'Boats', 'name_ar' => 'قوارب']);
    $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerCategory, 'name' => 'Boats contract']);
    $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'عقد', 'body_en' => 'Contract']), 1);
    ContractAssignment::query()->create(['contract_template_id' => $template->id, 'country_id' => $this->country->id, 'subject_type' => ContractAssignment::SUBJECT_CLASSIFIED_CATEGORY, 'subject_key' => (string) $category->id]);
    $this->withoutVite();

    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'classifieds/categories'))->assertOk()->assertSee('Boats')->assertDontSee(__('Require a contract'));
    $admin = adminAccount();
    $this->actingAs($admin)->get(onPanel('admin', 'classifieds/categories'))->assertOk()->assertSee('Boats')->assertSee(__('Require a contract'));
    $this->actingAs($admin)->post(onPanel('admin', "classifieds/categories/{$category->id}/contract"), ['requires_contract' => '1'])->assertRedirect();
    expect($category->refresh()->requires_contract)->toBeTrue();

    app('auth')->forgetGuards();
    [, $token] = customerWithToken();
    $number = $this->withToken($token)->postJson('/api/v1/uae-en/classifieds/ads', ['category_id' => $category->id, 'title' => 'Speed boat 2020', 'price' => '90000'])->assertCreated()->json('data.number');
    $this->withToken($token)->post("/api/v1/uae-en/classifieds/ads/{$number}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertSuccessful();
    $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/submit")->assertOk()
        ->assertJsonPath('data.status', 'pending_contract')->assertJsonPath('data.contract.version_id', $version->id);
    $this->withToken($token)->postJson("/api/v1/uae-en/classifieds/ads/{$number}/contract", ['full_name' => 'Omar Saleh'])->assertOk()->assertJsonPath('data.status', 'pending_review');
    expect(ClassifiedAd::query()->where('number', $number)->sole()->contract_signature_id)->not->toBeNull();
});

it('RT-007: the Seller Agreement gates Seller Center and the vendor app until it is signed on the web', function () {
    [$owner, $vendor] = sellerAccount('uae', ['seller_agreement_accepted_at' => null]);
    $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller Agreement']);
    $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Agreement for {store_name}']));
    $token = $owner->createToken('app', ['ops:vendor'])->plainTextToken;
    $this->withoutVite();

    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/agreement')->assertOk()->assertJsonPath('data.required', true)->assertJsonPath('data.signed', false);
    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/orders')->assertForbidden()->assertJsonPath('error.code', 'seller_agreement_required');
    app('auth')->forgetGuards();

    $this->actingAs($owner)->get(onPanel('partner', 'products'))->assertRedirect(onPanel('partner', 'contracts'));
    $this->actingAs($owner)->post(onPanel('partner', "contracts/{$version->id}/sign"), ['full_name' => 'Owner Name', 'accept' => '1'])->assertRedirect(onPanel('partner', 'contracts'));
    $this->actingAs($owner)->get(onPanel('partner', 'products'))->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/uae-en/ops/vendor/orders')->assertOk();
});

it('RT-009: the Arabic no-workspace message names the workspace in Arabic', function () {
    [$owner] = sellerAccount('uae');
    $token = $owner->createToken('app', ['ops:vendor'])->plainTextToken;

    $message = $this->withToken($token)->getJson('/api/v1/omn-ar/ops/vendor/orders')->assertForbidden()
        ->assertJsonPath('error.code', 'no_workspace')->json('error.message');

    expect($message)->toContain('البائع')->not->toContain('vendor');
    expect($this->withToken($token)->getJson('/api/v1/omn-en/ops/vendor/orders')->json('error.message'))->toContain('no seller workspace');
});

it('RT-010: the returns support category reads "Returns & refunds" in English and Arabic', function () {
    [, $token] = customerWithToken();

    $this->withToken($token)->getJson('/api/v1/uae-en/support/categories')->assertOk()
        ->assertJsonFragment(['value' => 'returns_refunds', 'label' => 'Returns & refunds']);
    $this->withToken($token)->getJson('/api/v1/uae-ar/support/categories')->assertOk()
        ->assertJsonFragment(['value' => 'returns_refunds', 'label' => 'المرتجعات والمبالغ المستردة']);
});

it('RT-013: the mail header links to the storefront, not the API host', function () {
    config(['app.url' => 'http://api.rt.test', 'nawy.payments.storefront_url' => 'https://shop.rt.test']);

    $html = (string) (new RequestNotification('test', 'Subject', ['Line']))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('href="https://shop.rt.test"')->not->toContain('http://api.rt.test');
});
