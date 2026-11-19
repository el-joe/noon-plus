<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sellerCouponPayload(array $overrides = []): array
{
    return $overrides + [
        'code' => 'shop10', 'type' => 'percentage', 'value' => '10', 'target_type' => 'all', 'customer_segment' => 'everyone',
        'first_order_only' => 0, 'is_active' => 1, 'is_public' => 0, 'ends_at' => now('Asia/Dubai')->addDays(30)->format('Y-m-d\TH:i'),
    ];
}

function sellerStaff(Vendor $vendor, ?string $tenantRole): User
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::ProductVendor, 'country_id' => test()->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $vendor->id, 'tenant_role' => $tenantRole]);

    return $user;
}

function sellerCheckout(User $customer, array $lines, string $code): string
{
    app('auth')->forgetGuards();
    $token = $customer->createToken('test')->plainTextToken;

    foreach ($lines as [$listing, $quantity]) {
        test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity])->assertOk();
    }

    test()->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => $code])->assertOk();

    return $token;
}

it('lets the owner create a vendor-funded coupon on their own products, with is_public and country-time dates', function () {
    [$owner, $vendor] = sellerAccount();
    $listing = sellableListing('100.00', vendor: $vendor);
    $product = $listing->variant->product;

    $this->actingAs($owner)->get(onPanel('partner', 'coupons/create'))->assertOk()->assertSee($product->title_en);
    $this->actingAs($owner)->post(onPanel('partner', 'coupons'), sellerCouponPayload([
        'target_type' => 'product', 'target_product_ids' => [$product->id], 'is_public' => 1,
        'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-31T23:59', 'usage_limit' => 50,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $coupon = Coupon::query()->sole();
    expect($coupon->code)->toBe('SHOP10')
        ->and($coupon->scope)->toBe(CouponScope::Vendor)
        ->and($coupon->vendor_id)->toBe($vendor->id)
        ->and($coupon->funded_by)->toBe(CouponFunding::Vendor)
        ->and($coupon->target_type)->toBe(CouponTarget::Product)
        ->and($coupon->targetIds())->toBe([$product->id])
        ->and($coupon->is_public)->toBeTrue()
        ->and($coupon->created_by_user_id)->toBe($owner->id)
        ->and($coupon->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 05:00');

    $this->actingAs($owner)->get(onPanel('partner', 'coupons'))->assertOk()->assertSee('SHOP10')->assertSee('Shown to shoppers');
    $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}"))->assertOk()->assertSee('SHOP10')->assertSee($product->title_en);
    $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}/edit"))->assertOk();

    $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => 'SHOP10', 'value' => '15', 'is_public' => 0]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($coupon->refresh()->value)->toBe('15.0000')->and($coupon->is_public)->toBeFalse()->and($coupon->target_type)->toBe(CouponTarget::All);
});

it('refuses other stores\' targets, free shipping, too-big discounts, long runs, taken codes and too many live coupons', function () {
    [$owner, $vendor] = sellerAccount();
    $mine = sellableListing(vendor: $vendor)->variant->product;
    $theirs = sellableListing()->variant->product;
    $foreignCategory = Category::factory()->create();
    Coupon::factory()->create(['code' => 'TAKEN']);
    $post = fn (array $data) => $this->actingAs($owner)->post(onPanel('partner', 'coupons'), sellerCouponPayload($data));

    $post(['target_type' => 'product', 'target_product_ids' => [$mine->id, $theirs->id]])->assertSessionHasErrors('target_product_ids');
    $post(['target_type' => 'category', 'target_category_ids' => [$foreignCategory->id]])->assertSessionHasErrors('target_category_ids');
    $post(['target_type' => 'brand'])->assertSessionHasErrors('target_brand_ids');
    $post(['target_type' => 'vendor', 'target_vendor_ids' => (string) $vendor->id])->assertSessionHasErrors('target_type');
    $post(['type' => 'free_shipping', 'value' => null])->assertSessionHasErrors('type');
    $post(['value' => '95'])->assertSessionHasErrors('value');
    $post(['type' => 'fixed_amount', 'value' => '0'])->assertSessionHasErrors('value');
    $post(['type' => 'bogo', 'value' => '100'])->assertSessionHasErrors(['buy_quantity', 'get_quantity']);
    $post(['ends_at' => null])->assertSessionHasErrors('ends_at');
    $post(['ends_at' => now('Asia/Dubai')->addDays(400)->format('Y-m-d\TH:i')])->assertSessionHasErrors('ends_at');
    $post(['starts_at' => '2027-01-10T00:00', 'ends_at' => '2027-01-05T00:00'])->assertSessionHasErrors('ends_at');
    $post(['code' => 'taken'])->assertSessionHasErrors('code');
    $post(['code' => 'no spaces!'])->assertSessionHasErrors('code');
    expect(Coupon::query()->count())->toBe(1);

    app(SettingsService::class)->set('coupons.seller_max_live', 1, $this->country);
    $post(['code' => 'FIRST'])->assertSessionHasNoErrors();
    $post(['code' => 'SECOND'])->assertSessionHasErrors('is_active');
    $post(['code' => 'SECOND', 'is_active' => 0])->assertSessionHasNoErrors();
    $second = Coupon::query()->where('code', 'SECOND')->sole();
    $this->actingAs($owner)->post(onPanel('partner', "coupons/{$second->id}/toggle"))->assertSessionHasErrors('is_active');
    expect($second->refresh()->is_active)->toBeFalse();

    app(SettingsService::class)->set('coupons.seller_max_percent', 50, $this->country);
    $post(['code' => 'BIG', 'value' => '60', 'is_active' => 0])->assertSessionHasErrors('value');
});

it('follows the staff matrix: marketing and manager manage, finance and viewer only look, catalog and orders are refused', function () {
    [, $vendor] = sellerAccount();
    $coupon = Coupon::factory()->create(['code' => 'STAFF', 'scope' => CouponScope::Vendor, 'vendor_id' => $vendor->id, 'funded_by' => CouponFunding::Vendor]);

    foreach (['marketing', 'manager'] as $role) {
        $user = sellerStaff($vendor, $role);
        $this->actingAs($user)->get(onPanel('partner', 'coupons'))->assertOk()->assertSee('STAFF');
        $this->actingAs($user)->post(onPanel('partner', 'coupons'), sellerCouponPayload(['code' => 'BY'.strtoupper($role)]))->assertRedirect()->assertSessionHasNoErrors();
    }

    foreach (['finance', null] as $role) {
        $user = sellerStaff($vendor, $role);
        $this->actingAs($user)->get(onPanel('partner', "coupons/{$coupon->id}"))->assertOk();
        $this->actingAs($user)->post(onPanel('partner', 'coupons'), sellerCouponPayload(['code' => 'NOPE']))->assertForbidden();
        $this->actingAs($user)->post(onPanel('partner', "coupons/{$coupon->id}/toggle"))->assertForbidden();
    }

    foreach (['catalog', 'orders'] as $role) {
        $this->actingAs(sellerStaff($vendor, $role))->get(onPanel('partner', 'coupons'))->assertForbidden();
    }

    expect(Coupon::query()->pluck('code')->sort()->values()->all())->toBe(['BYMANAGER', 'BYMARKETING', 'STAFF']);
});

it('scopes every page to the store: another store\'s coupon is a 404 and never listed', function () {
    [$owner] = sellerAccount();
    $rival = Vendor::factory()->create();
    $theirs = Coupon::factory()->create(['code' => 'RIVAL', 'scope' => CouponScope::Vendor, 'vendor_id' => $rival->id, 'funded_by' => CouponFunding::Vendor]);
    $platform = Coupon::factory()->create(['code' => 'NAWY']);

    $this->actingAs($owner)->get(onPanel('partner', 'coupons'))->assertOk()->assertDontSee('RIVAL')->assertDontSee('NAWY');

    foreach ([$theirs, $platform] as $coupon) {
        $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}"))->assertNotFound();
        $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}/edit"))->assertNotFound();
        $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => $coupon->code]))->assertNotFound();
        $this->actingAs($owner)->post(onPanel('partner', "coupons/{$coupon->id}/toggle"))->assertNotFound();
    }

    expect($theirs->refresh()->is_active)->toBeTrue();
});

it('keeps countries apart: an Oman store\'s coupons are not seen from the UAE store and use the Oman database', function () {
    [$uaeOwner] = sellerAccount('uae');
    [$omanOwner, $omanVendor] = sellerAccount('omn');
    $oman = Country::query()->where('code', 'omn')->sole();

    app(CountryContext::class)->runAs($oman, function () use ($omanVendor): void {
        Coupon::factory()->create(['code' => 'MUSCAT', 'scope' => CouponScope::Vendor, 'vendor_id' => $omanVendor->id, 'funded_by' => CouponFunding::Vendor]);
    });

    $this->actingAs($uaeOwner)->get(onPanel('partner', 'coupons'))->assertOk()->assertDontSee('MUSCAT');
    expect(Coupon::query()->where('code', 'MUSCAT')->exists())->toBeFalse();

    app('auth')->forgetGuards();
    $this->actingAs($omanOwner)->get(onPanel('partner', 'coupons'))->assertOk()->assertSee('MUSCAT');
});

it('discounts only the store\'s own items at checkout, takes it from that store\'s settlement and lists the redemption', function () {
    [$owner, $vendor] = sellerAccount();
    $mine = sellableListing('200.00', vendor: $vendor);
    $other = sellableListing('100.00');
    $this->actingAs($owner)->post(onPanel('partner', 'coupons'), sellerCouponPayload(['code' => 'MINE10']))->assertSessionHasNoErrors();

    $customer = User::factory()->create(['email_verified_at' => now()]);
    $token = sellerCheckout($customer, [[$mine, 1], [$other, 1]], 'MINE10');
    $this->withToken($token)->getJson('/api/v1/uae-en/cart')->assertJsonPath('data.totals.discount.amount', '20.00');
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
        'payment_method' => 'cod',
        'address' => ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
    ])->assertCreated();

    $order = Order::query()->where('customer_user_id', $customer->id)->sole();
    foreach ($order->subOrders as $subOrder) {
        moveSubOrderTo($subOrder, SubOrderStatus::Completed);
    }

    expect((string) SubOrderSettlement::query()->where('vendor_id', $vendor->id)->sole()->vendor_discount)->toBe('20.0000')
        ->and((string) SubOrderSettlement::query()->where('vendor_id', $other->vendor_id)->sole()->vendor_discount)->toBe('0.0000');
    assertLedgerBalanced();

    $coupon = Coupon::query()->where('code', 'MINE10')->sole();
    app('auth')->forgetGuards();
    $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}"))->assertOk()
        ->assertSee($order->number)->assertViewHas('given', fn ($given): bool => (string) $given->getAmount() === '20.00');
});

it('only counts the store\'s own items when deciding whether a targeted seller coupon fits the cart', function () {
    [, $vendor] = sellerAccount();
    $shoes = Category::factory()->create();
    $mine = sellableListing('50.00', vendor: $vendor);
    $theirsInShoes = Listing::factory()->for(Vendor::factory()->create())->active()->create(['price' => '80.00']);
    $theirsInShoes->variant->product->update(['category_id' => $shoes->id]);
    app(InventoryLedger::class)->move($theirsInShoes, 5, 'restock');
    $coupon = Coupon::factory()->create(['code' => 'SHOES', 'scope' => CouponScope::Vendor, 'vendor_id' => $vendor->id, 'funded_by' => CouponFunding::Vendor,
        'target_type' => CouponTarget::Category, 'target_ids' => [$shoes->id]]);

    $customer = User::factory()->create(['email_verified_at' => now()]);
    $token = $customer->createToken('test')->plainTextToken;
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $mine->id, 'quantity' => 1])->assertOk();
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $theirsInShoes->id, 'quantity' => 1])->assertOk();

    $this->withToken($token)->postJson('/api/v1/uae-en/cart/coupons', ['code' => $coupon->code])->assertStatus(422)->assertJsonPath('error.code', 'coupon_no_eligible_items');
});

it('locks the code and discount once used, but keeps limits and dates editable', function () {
    [$owner, $vendor] = sellerAccount();
    $this->actingAs($owner)->post(onPanel('partner', 'coupons'), sellerCouponPayload(['code' => 'USED']))->assertSessionHasNoErrors();
    $coupon = Coupon::query()->sole();
    $order = subOrderFor($vendor)->order;
    CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'customer_user_id' => $order->customer_user_id, 'discount_amount' => '5.00', 'funded_by' => 'vendor', 'vendor_shares' => [$vendor->id => '5.00']]);

    $this->actingAs($owner)->get(onPanel('partner', "coupons/{$coupon->id}/edit"))->assertOk()->assertSee('cannot change');
    $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => 'USED', 'value' => '20']))->assertSessionHasErrors('code');
    $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => 'RENAMED']))->assertSessionHasErrors('code');
    $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => 'USED', 'usage_limit' => 10]))->assertSessionHasNoErrors();

    expect($coupon->refresh()->usage_limit)->toBe(10)->and($coupon->value)->toBe('10.0000');
});

it('lets admin marketing see and switch off a seller coupon, which the seller then cannot switch back on', function () {
    [$owner, $vendor] = sellerAccount();
    $this->actingAs($owner)->post(onPanel('partner', 'coupons'), sellerCouponPayload(['code' => 'SELLER5']))->assertSessionHasNoErrors();
    $coupon = Coupon::query()->sole();
    $marketing = adminAccount('uae', 'marketing');

    app('auth')->forgetGuards();
    $this->actingAs($marketing)->get(onPanel('admin', 'coupons?scope=vendor'))->assertOk()->assertSee('SELLER5');
    $this->actingAs($marketing)->post(onPanel('admin', "coupons/{$coupon->id}/toggle"))->assertRedirect();
    expect($coupon->refresh()->is_active)->toBeFalse()->and($coupon->admin_disabled_at)->not->toBeNull();
    $this->actingAs($marketing)->get(onPanel('admin', "coupons/{$coupon->id}"))->assertOk()->assertSee('Switched off by Nawy');

    app('auth')->forgetGuards();
    $this->actingAs($owner)->get(onPanel('partner', 'coupons'))->assertOk()->assertSee('Switched off by Nawy');
    $this->actingAs($owner)->post(onPanel('partner', "coupons/{$coupon->id}/toggle"))->assertSessionHasErrors('is_active');
    $this->actingAs($owner)->put(onPanel('partner', "coupons/{$coupon->id}"), sellerCouponPayload(['code' => 'SELLER5']))->assertSessionHasErrors('is_active');
    expect($coupon->refresh()->is_active)->toBeFalse();

    app('auth')->forgetGuards();
    $this->actingAs($marketing)->post(onPanel('admin', "coupons/{$coupon->id}/toggle"))->assertRedirect();
    expect($coupon->refresh()->is_active)->toBeTrue()->and($coupon->admin_disabled_at)->toBeNull();

    app('auth')->forgetGuards();
    $this->actingAs($owner)->post(onPanel('partner', "coupons/{$coupon->id}/toggle"))->assertRedirect()->assertSessionHasNoErrors();
    expect($coupon->refresh()->is_active)->toBeFalse()->and($coupon->admin_disabled_at)->toBeNull();

    $support = adminAccount('uae', 'support');
    app('auth')->forgetGuards();
    $this->actingAs($support)->post(onPanel('admin', "coupons/{$coupon->id}/toggle"))->assertForbidden();
});

it('links Coupons and Earnings in the Seller Center sidebar instead of "Soon"', function () {
    [$owner] = sellerAccount();

    $this->actingAs($owner)->get(onPanel('partner', 'coupons'))->assertOk()
        ->assertSee('href="'.onPanel('partner', 'coupons').'"', false)
        ->assertSee('href="'.onPanel('partner', 'earnings').'"', false);
});
