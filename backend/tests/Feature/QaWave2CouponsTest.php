<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Listings\Models\Listing;
use App\Domains\Membership\Enums\MembershipStatus;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

/**
 * Items in a customer's cart (signed in), returns the token.
 *
 * @param  array<int, array{0: Listing, 1: int}>  $lines
 */
function couponCart(User $customer, array $lines, string $market = 'uae-en'): string
{
    app('auth')->forgetGuards();
    $token = $customer->createToken('test')->plainTextToken;

    foreach ($lines as [$listing, $quantity]) {
        test()->withToken($token)->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => $quantity])->assertOk();
    }

    return $token;
}

function verifiedCustomer(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponPayload(array $overrides = []): array
{
    return $overrides + [
        'code' => 'save10', 'scope' => 'platform', 'funded_by' => 'platform', 'type' => 'percentage', 'value' => '10',
        'target_type' => 'all', 'customer_segment' => 'everyone', 'first_order_only' => 0, 'is_active' => 1,
    ];
}

it('QC-014: marketing creates a coupon with dates in country time; validation refuses impossible coupons; changes are audited', function () {
    $marketing = adminAccount('uae', 'marketing');
    $category = Category::factory()->create();

    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload([
        'target_type' => 'category', 'target_category_ids' => [$category->id], 'usage_limit' => 100, 'per_customer_limit' => 1,
        'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-31T23:59',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $coupon = Coupon::query()->sole();
    expect($coupon->code)->toBe('SAVE10')
        ->and($coupon->target_type)->toBe(CouponTarget::Category)
        ->and($coupon->targetIds())->toBe([$category->id])
        ->and($coupon->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 05:00')
        ->and(Activity::query()->where('log_name', 'coupons')->sole()->properties['country'])->toBe('uae');

    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'BAD1', 'value' => '150']))->assertSessionHasErrors('value');
    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'BAD2', 'scope' => 'vendor', 'vendor_id' => Vendor::factory()->create()->id]))->assertSessionHasErrors('funded_by');
    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'BAD3', 'type' => 'bogo', 'value' => null]))->assertSessionHasErrors(['buy_quantity', 'get_quantity']);
    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'BAD4', 'target_type' => 'brand']))->assertSessionHasErrors('target_type');
    $this->actingAs($marketing)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'save10']))->assertSessionHasErrors('code');

    $this->actingAs($marketing)->get(onPanel('admin', 'coupons?type=percentage'))->assertOk()->assertSee('SAVE10');
    $this->actingAs($marketing)->get(onPanel('admin', "coupons/{$coupon->id}"))->assertOk();

    $finance = adminAccount('uae', 'finance');
    $this->actingAs($finance)->get(onPanel('admin', 'coupons'))->assertOk();
    $this->actingAs($finance)->post(onPanel('admin', 'coupons'), couponPayload(['code' => 'FIN']))->assertForbidden();
    $this->actingAs(adminAccount('omn', 'marketing'))->get(onPanel('admin', "coupons/{$coupon->id}"))->assertNotFound();
});

it('QC-014: a category coupon discounts only items of that category and its sub-categories', function () {
    $fashion = Category::factory()->create();
    $abayas = Category::factory()->create(['parent_id' => $fashion->id]);
    $abaya = sellableListing('200.00');
    $abaya->variant->product->update(['category_id' => $abayas->id]);
    $phone = sellableListing('300.00');
    Coupon::factory()->create(['code' => 'FASHION10', 'target_type' => CouponTarget::Category, 'target_ids' => [$fashion->id], 'value' => '10']);

    $token = couponCart(verifiedCustomer(), [[$abaya, 1], [$phone, 1]]);
    $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'FASHION10'])->assertOk()
        ->assertJsonPath('data.coupons.0.discount.amount', '20.00')
        ->assertJsonPath('data.totals.discount.amount', '20.00');
});

it('QC-014: a brand coupon for a fixed amount is capped at the eligible items and refused when none are in the cart', function () {
    $brand = Brand::factory()->create();
    $headphones = sellableListing('15.00');
    $headphones->variant->product->update(['brand_id' => $brand->id]);
    Coupon::factory()->create(['code' => 'BRAND50', 'type' => CouponType::FixedAmount, 'value' => '50', 'target_type' => CouponTarget::Brand, 'target_ids' => [$brand->id]]);

    $empty = couponCart(verifiedCustomer(), [[sellableListing('80.00'), 1]]);
    $this->withToken($empty)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'BRAND50'])->assertUnprocessable()->assertJsonPath('error.code', 'coupon_no_eligible_items');

    $token = couponCart(verifiedCustomer(), [[$headphones, 2], [sellableListing('80.00'), 1]]);
    $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'BRAND50'])->assertOk()
        ->assertJsonPath('data.totals.discount.amount', '30.00');
});

it('QC-014: BOGO buy 2 get 1 makes the cheapest eligible unit free, spread to the seller that sold it', function () {
    $sellerA = Vendor::factory()->create();
    $sellerB = Vendor::factory()->create();
    Coupon::factory()->create(['code' => 'B2G1', 'type' => CouponType::Bogo, 'value' => '100', 'buy_quantity' => 2, 'get_quantity' => 1]);

    $token = couponCart(verifiedCustomer(), [[sellableListing('40.00', vendor: $sellerA), 2], [sellableListing('25.00', vendor: $sellerB), 1]]);
    $response = $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'B2G1'])->assertOk()
        ->assertJsonPath('data.totals.discount.amount', '25.00');
    $shipments = collect($response->json('data.shipments'))->keyBy('seller');
    expect($shipments[$sellerB->store_name]['discount']['amount'])->toBe('25.00')
        ->and($shipments[$sellerA->store_name]['discount']['amount'])->toBe('0.00');

    $short = couponCart(verifiedCustomer(), [[sellableListing('40.00'), 2]]);
    $this->withToken($short)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'B2G1'])->assertOk()
        ->assertJsonPath('data.totals.discount.amount', '0.00');
});

it('QC-014: BOGO at 50 % off in Oman rounds half-up to 3 decimals', function () {
    $this->actAsCountry('omn');
    Coupon::factory()->create(['code' => 'HALF', 'type' => CouponType::Bogo, 'value' => '50', 'buy_quantity' => 1, 'get_quantity' => 1]);

    $token = couponCart(verifiedCustomer(), [[sellableListing('3.125'), 2]], 'omn-en');
    $this->withToken($token)->postJson('/api/v1/omn-en/cart/coupons', ['code' => 'HALF'])->assertOk()
        ->assertJsonPath('data.totals.discount.amount', '1.563');
});

it('QC-014: first-order coupons need a signed-in customer without orders; segments limit who may use a coupon', function () {
    Coupon::factory()->create(['code' => 'WELCOME', 'first_order_only' => true]);
    Coupon::factory()->create(['code' => 'MEMBERS', 'customer_segment' => CustomerSegment::NawyOneMembers]);
    $listing = sellableListing('100.00', stock: 20);

    $guest = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->headers->get('X-Cart-Token');
    $this->withHeader('X-Cart-Token', $guest)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'WELCOME'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'coupon_sign_in_required');

    $newcomer = verifiedCustomer();
    $this->withToken(couponCart($newcomer, [[$listing, 1]]))->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'WELCOME'])->assertOk();

    [, $returning] = placeOrderVia('cod');
    $this->withToken(couponCart($returning, [[$listing, 1]]))->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'WELCOME'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'coupon_first_order_only');
    $this->withToken(couponCart($returning, [[$listing, 1]]))->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'MEMBERS'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'coupon_segment');

    $plan = MembershipPlan::query()->create(['code' => 'one', 'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly', 'price' => '25.00']);
    Membership::query()->create(['customer_user_id' => $returning->id, 'membership_plan_id' => $plan->id, 'status' => MembershipStatus::Active, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
    $this->withToken(couponCart($returning, [[$listing, 1]]))->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'MEMBERS'])->assertOk();
});

it('QC-014: a vendor-funded Nawy coupon is taken from each seller\'s settlement by their share; a platform-funded one is not', function () {
    $sellerA = Vendor::factory()->create();
    $sellerB = Vendor::factory()->create();
    Coupon::factory()->create(['code' => 'COFUND', 'value' => '10', 'funded_by' => CouponFunding::Vendor]);
    $customer = verifiedCustomer();
    $token = couponCart($customer, [[sellableListing('300.00', vendor: $sellerA), 1], [sellableListing('100.00', vendor: $sellerB), 1]]);
    $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => 'COFUND'])->assertOk()->assertJsonPath('data.totals.discount.amount', '40.00');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
        'payment_method' => 'cod',
        'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
    ])->assertCreated();

    $order = Order::query()->where('customer_user_id', $customer->id)->sole();
    $redemption = CouponRedemption::query()->sole();
    expect($redemption->funded_by)->toBe('vendor')
        ->and($redemption->vendor_shares)->toEqual([$sellerA->id => '30.00', $sellerB->id => '10.00']);

    foreach ($order->subOrders as $subOrder) {
        moveSubOrderTo($subOrder, SubOrderStatus::Completed);
    }

    expect((string) SubOrderSettlement::query()->where('vendor_id', $sellerA->id)->sole()->vendor_discount)->toBe('30.0000')
        ->and((string) SubOrderSettlement::query()->where('vendor_id', $sellerB->id)->sole()->vendor_discount)->toBe('10.0000');
    assertLedgerBalanced();
});

it('QC-019: an account manager sees only their vendors\' seller coupons and cannot create coupons', function () {
    $manager = adminAccount('uae', 'account_manager');
    $assigned = Vendor::factory()->create(['account_manager_id' => $manager->id]);
    Coupon::factory()->forVendor($assigned)->create(['code' => 'MINE5', 'funded_by' => CouponFunding::Vendor]);
    Coupon::factory()->forVendor(Vendor::factory()->create())->create(['code' => 'THEIRS5', 'funded_by' => CouponFunding::Vendor]);
    $platform = Coupon::factory()->create(['code' => 'NAWYWIDE']);

    $this->actingAs($manager)->get(onPanel('admin', 'coupons'))->assertOk()->assertSee('MINE5')->assertDontSee('THEIRS5')->assertDontSee('NAWYWIDE');
    $this->actingAs($manager)->get(onPanel('admin', "coupons/{$platform->id}"))->assertNotFound();
    $this->actingAs($manager)->get(onPanel('admin', 'coupons/create'))->assertForbidden();
});
