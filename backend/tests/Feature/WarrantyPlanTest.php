<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Domains\Warranty\Models\WarrantyPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * A listing in a child category of the given parent.
 */
function listingUnder(Category $parent, string $price): Listing
{
    $listing = sellableListing($price);
    $listing->variant->product->forceFill(['category_id' => Category::factory()->childOf($parent)->create()->id])->save();

    return $listing;
}

it('offers the parent category\'s active plans and adds the chosen one per unit to the total', function () {
    $electronics = Category::factory()->create();
    $listing = listingUnder($electronics, '249.00');
    $plan = WarrantyPlan::factory()->for($electronics)->create(['price' => '49.00', 'duration_months' => 24]);
    WarrantyPlan::factory()->for($electronics)->inactive()->create();
    WarrantyPlan::factory()->create();

    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2])
        ->assertJsonCount(1, 'data.shipments.0.items.0.warranty_options')
        ->headers->get('X-Cart-Token');

    $this->withHeader('X-Cart-Token', $token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.warranty.total.amount', '98.00')
        ->assertJsonPath('data.shipments.0.items.0.warranty.months', 24)
        ->assertJsonPath('data.shipments.0.total.amount', '498.00')
        ->assertJsonPath('data.totals.warranty.amount', '98.00')
        ->assertJsonPath('data.totals.total.amount', '596.00')
        ->assertJsonPath('data.totals.tax_included.amount', '28.38');
});

it('refuses a plan from another category and lets the customer remove the plan', function () {
    $listing = listingUnder(Category::factory()->create(), '100.00');
    $otherPlan = WarrantyPlan::factory()->create();
    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->headers->get('X-Cart-Token');

    $this->withHeader('X-Cart-Token', $token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $otherPlan->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'warranty_not_available');

    $this->withHeader('X-Cart-Token', $token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => null])
        ->assertOk()
        ->assertJsonPath('data.shipments.0.items.0.warranty', null)
        ->assertJsonPath('data.totals.warranty.amount', '0.00');
});

it('answers 404 for a warranty on an item that is not in the cart', function () {
    $plan = WarrantyPlan::factory()->create();

    $this->putJson('/api/v1/uae-en/cart/items/999999/warranty', ['warranty_plan_id' => $plan->id])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'item_not_in_cart');
});

it('drops a plan that was deactivated after it was chosen, with a warning', function () {
    $category = Category::factory()->create();
    $listing = listingUnder($category, '100.00');
    $plan = WarrantyPlan::factory()->for($category)->create();
    $token = $this->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->headers->get('X-Cart-Token');
    $this->withHeader('X-Cart-Token', $token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $plan->id])->assertOk();

    $plan->update(['is_active' => false]);

    $this->withHeader('X-Cart-Token', $token)->getJson('/api/v1/uae-en/cart')
        ->assertJsonPath('data.totals.warranty.amount', '0.00')
        ->assertJsonCount(1, 'data.warnings');
});

it('records warranty purchases at order placement and keeps plans out of the seller total', function () {
    $this->freezeTime();
    [$customer, $token] = customerWithToken();
    $category = Category::factory()->create();
    $listing = listingUnder($category, '200.00');
    $plan = WarrantyPlan::factory()->for($category)->create(['price' => '35.00', 'duration_months' => 12]);

    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 2]);
    $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $plan->id]);
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])
        ->assertCreated()
        ->assertJsonPath('data.totals.warranty.amount', '70.00')
        ->assertJsonPath('data.totals.total.amount', '470.00')
        ->assertJsonPath('data.shipments.0.total.amount', '400.00')
        ->assertJsonPath('data.shipments.0.items.0.warranty_total.amount', '70.00');

    $order = Order::query()->sole();
    expect(WarrantyPurchase::query()->sole())
        ->order_id->toBe($order->id)
        ->customer_user_id->toBe($customer->id)
        ->status->toBe(WarrantyPurchaseStatus::Active)
        ->quantity->toBe(2)
        ->total->toBe('70.0000')
        ->expires_at->toDateTimeString()->toBe(now()->addYear()->toDateTimeString())
        ->and(OrderItem::query()->sole()->warranty_plan_id)->toBe($plan->id)
        ->and($order->subOrders()->sole()->warranty_total)->toBe('70.0000');
});

it('rounds warranty totals to 3 decimals in Oman', function () {
    $this->actAsCountry('omn');
    $category = Category::factory()->create();
    $listing = listingUnder($category, '12.500');
    $plan = WarrantyPlan::factory()->for($category)->create(['price' => '1.2345']);

    $token = $this->putJson('/api/v1/omn-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 3])->headers->get('X-Cart-Token');

    $this->withHeader('X-Cart-Token', $token)->putJson("/api/v1/omn-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $plan->id])
        ->assertJsonPath('data.shipments.0.items.0.warranty.unit_price.amount', '1.235')
        ->assertJsonPath('data.totals.warranty', ['amount' => '3.705', 'currency' => 'OMR'])
        ->assertJsonPath('data.totals.total.amount', '41.205');
});

it('cancels the warranty purchase when its shipment is cancelled', function () {
    [, $token] = customerWithToken();
    $category = Category::factory()->create();
    $listing = listingUnder($category, '200.00');
    $plan = WarrantyPlan::factory()->for($category)->create();
    $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/warranty", ['warranty_plan_id' => $plan->id]);
    $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'card', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])->assertCreated();

    app(SubOrderStateMachine::class)->transition(Order::query()->sole()->subOrders()->sole(), SubOrderStatus::Cancelled, ActorType::Admin, 1, 'Out of stock');

    expect(WarrantyPurchase::query()->sole()->status)->toBe(WarrantyPurchaseStatus::Cancelled);
});

it('lists the warranty plans on the product page', function () {
    $category = Category::factory()->create();
    $listing = listingUnder($category, '200.00');
    WarrantyPlan::factory()->for($category)->create(['price' => '49.00', 'duration_months' => 24]);

    $this->getJson('/api/v1/uae-en/products/'.$listing->variant->product->slug)
        ->assertJsonCount(1, 'data.warranty_plans')
        ->assertJsonPath('data.warranty_plans.0.price.amount', '49.00')
        ->assertJsonPath('data.warranty_plans.0.months', 24);
});
