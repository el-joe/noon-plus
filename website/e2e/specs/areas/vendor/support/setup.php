<?php
// Seller Center browser-test records (test values only, never production).
// Run through `php artisan tinker --execute` with VEN_RUN set; prints one JSON line prefixed "VENDOR_FIXTURES=".
// Acts only on the e2e store's own e2e variants (never listing 19 or the seeded e2e orders) and on per-run test stores.
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Onboarding\Support\AcquirerReferrals;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Returns\Enums\ReturnMethod;
use App\Domains\Returns\Enums\ReturnReason;
use App\Domains\Returns\Support\ReturnRequests;
use App\Domains\Reviews\Support\ProductQuestions;
use App\Domains\Reviews\Support\ProductReviews;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Support\Str;

if (app()->environment('production')) { throw new RuntimeException('refuses production'); }
$run = strtolower((string) (getenv('VEN_RUN') ?: Str::random(6)));
$uae = Country::query()->where('code', 'uae')->sole();
$qat = Country::query()->where('code', 'qat')->sole();
$mkUser = function (string $email, string $name) {
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->forceFill(['name' => $name, 'password' => 'E2e-Passw0rd!', 'two_factor_enabled' => true, 'email_verified_at' => $user->email_verified_at ?? now(), 'status' => 'active'])->save();
    return $user;
};
$seller = User::query()->where('email', 'e2e-seller@nawy.test')->sole();
$customer = User::query()->where('email', 'e2e-customer@nawy.test')->sole();
$admin = User::query()->where('email', 'e2e-admin@nawy.test')->sole();
$fresh = $mkUser("e2e-ven-{$run}@nawy.test", "E2E Fresh Seller {$run}");
$gateUser = $mkUser("e2e-ven-gate-{$run}@nawy.test", "E2E Gate Seller {$run}");

$out = app(CountryContext::class)->runAs($uae, function () use ($uae, $run, $seller, $customer, $admin, $fresh) {
    $store = Vendor::query()->where('slug', 'e2e-store')->sole();
    $product = Product::query()->where('status', 'active')->orderBy('id')->firstOrFail();

    // The order listing: its own e2e variant, active, kept stocked. It shares the product with the
    // customer specs, so it is priced above their offers (FBN AED 95, seller-fulfilled AED 120): the
    // lowest price wins the buy box, and an AED 80 offer took the product page and cart away from them.
    $variant = ProductVariant::query()->firstOrCreate(['sku' => 'E2E-VEN-ORD'], ['product_id' => $product->id, 'weight_grams' => 300]);
    $listing = Listing::query()->firstOrCreate(
        ['vendor_id' => $store->id, 'product_variant_id' => $variant->id, 'condition' => 'new'],
        ['price' => '135.0000', 'fulfilment' => 'fbm', 'handling_days' => 1],
    );
    if ((float) $listing->price < 135 || $listing->sale_price !== null) {
        $listing->forceFill(['price' => '135.0000', 'sale_price' => null])->save();
    }
    if ($listing->status !== ListingStatus::Active) {
        $from = $listing->status;
        $listing->forceFill(['status' => ListingStatus::Active])->save();
        $listing->statusHistory()->create(['from_status' => $from, 'to_status' => ListingStatus::Active, 'actor_user_id' => null, 'reason' => 'e2e vendor area']);
    }
    $available = (int) Listing::query()->withAvailableQuantity()->whereKey($listing->id)->value('available_quantity');
    if ($available < 30) {
        app(InventoryLedger::class)->move($listing, 30 - $available, 'restock');
    }
    $listing->load('variant.product');

    // Two fresh catalogue variants the run lists from the browser (one product, two variants).
    $newVariants = [];
    foreach (['A', 'B'] as $suffix) {
        $sku = strtoupper("E2E-VEN-{$run}-{$suffix}");
        $newVariants[] = ProductVariant::query()->firstOrCreate(['sku' => $sku], ['product_id' => $product->id, 'weight_grams' => 250])->only(['id', 'sku']);
    }

    // A live FBM listing of this run that the browser enrols in Fulfilled by Nawy.
    $fbnVariant = ProductVariant::query()->firstOrCreate(['sku' => strtoupper("E2E-VEN-FBN-{$run}")], ['product_id' => $product->id, 'weight_grams' => 600]);
    $fbnListing = Listing::query()->firstOrCreate(
        ['vendor_id' => $store->id, 'product_variant_id' => $fbnVariant->id, 'condition' => 'new'],
        ['price' => '99.0000', 'fulfilment' => 'fbm', 'handling_days' => 1],
    );
    if ($fbnListing->status !== ListingStatus::Active) {
        $fbnListing->forceFill(['status' => ListingStatus::Active])->save();
        $fbnListing->statusHistory()->create(['from_status' => null, 'to_status' => ListingStatus::Active, 'actor_user_id' => null, 'reason' => 'e2e vendor area']);
    }
    $warehouse = \App\Domains\Fulfilment\Models\Warehouse::query()->where('code', 'E2E-FC')->sole();

    // One-item COD sub-orders of the store on the order listing (as E2eSeeder::subOrder).
    $subOrder = function (SubOrderStatus $status) use ($uae, $store, $customer, $listing) {
        app(InventoryLedger::class)->move($listing, -1, 'order_placed');
        $order = Order::query()->create([
            'number' => 'NW-'.strtoupper($uae->iso2).'-E2EV'.strtoupper(Str::random(5)),
            'customer_user_id' => $customer->id, 'status' => 'placed', 'payment_method' => 'cod', 'currency' => $uae->currency,
            'subtotal' => $listing->price, 'grand_total' => $listing->price,
            'shipping_address' => ['name' => 'E2E Customer', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'E2E Tower', 'phone' => '+971500000098'],
            'placed_at' => now(),
        ]);
        $sub = $order->subOrders()->create(['vendor_id' => $store->id, 'number' => $order->number.'-1', 'subtotal' => $order->subtotal, 'total' => $order->grand_total]);
        $sub->items()->create(['listing_id' => $listing->id, 'sku' => $listing->variant->sku, 'title' => $listing->variant->product->title_en, 'quantity' => 1, 'unit_price' => $listing->price, 'line_total' => $listing->price]);
        $sub->statusHistory()->create(['to_status' => SubOrderStatus::Placed, 'actor_type' => ActorType::Customer, 'actor_user_id' => $customer->id]);
        if ($status !== SubOrderStatus::Placed) {
            $sub->forceFill(['status' => $status, 'delivered_at' => $status === SubOrderStatus::Delivered ? now() : null])->save();
            $sub->items()->update(['fulfillment_status' => $status->value]);
        }
        if ($status === SubOrderStatus::Delivered) {
            // The driver collected the COD cash at the door (as the delivery app records it).
            \App\Domains\Finance\Models\CodCollection::query()->create(['sub_order_id' => $sub->id, 'order_id' => $order->id, 'currency' => $uae->currency, 'amount' => $listing->price, 'cod_fee' => '0', 'collected_at' => now()]);
        }
        return $sub->fresh(['items', 'order']);
    };
    $toShip = $subOrder(SubOrderStatus::Placed);
    $toCancel = $subOrder(SubOrderStatus::Placed);
    $delivered = $subOrder(SubOrderStatus::Delivered);
    $deliveredForReturn = $subOrder(SubOrderStatus::Delivered);

    // A customer review (approved by moderation) and an approved question on the product.
    $review = app(ProductReviews::class)->create($customer, $delivered->items->first()->id, ['rating' => 4, 'title' => "E2E review {$run}", 'body' => "Good parcel, browser test {$run}."]);
    $review->forceFill(['status' => 'published'])->save();
    $question = app(ProductQuestions::class)->ask($customer, $product, "E2E question {$run}: does it come with a warranty?");
    $question->forceFill(['status' => 'approved'])->save();

    // A return requested by the customer on the second delivered sub-order.
    $return = app(ReturnRequests::class)->create($deliveredForReturn, $customer->id, [['order_item_id' => $deliveredForReturn->items->first()->id, 'quantity' => 1]],
        ReturnReason::Defective, ReturnMethod::DropOff, RefundDestination::Original, "E2E return {$run}");

    // A brand-new store for this run (subscription, coupon limits): no listings, agreement not needed in uae.
    $freshStore = Vendor::query()->firstOrCreate(['slug' => "e2e-ven-{$run}"], [
        'owner_user_id' => $fresh->id, 'type' => 'product', 'store_name' => "E2E Fresh Store {$run}", 'status' => Vendor::STATUS_ACTIVE,
        'contact_phone' => '+971500000095', 'verified_at' => now(), 'seller_agreement_accepted_at' => now(),
    ]);
    $fresh->memberships()->firstOrCreate(
        ['role' => MembershipRole::ProductVendor, 'country_id' => $uae->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $freshStore->id],
        ['status' => 'active'],
    );

    // A bookable paid placement of our own (placement "e2e_test": no storefront page asks for it).
    $slot = \App\Domains\Advertising\Models\AdSlot::query()->firstOrCreate(['code' => 'e2e-vendor-test'], [
        'name' => 'E2E test placement', 'placement' => 'e2e_test', 'price_per_day' => '5.0000', 'max_concurrent' => 100, 'is_active' => true,
    ]);

    // A flash sale of our own for this run, open for submissions (the dev ones are draft, live or cancelled), starting in 5 days.
    // One per run: a withdrawn offer cannot be offered again to the same event (withdrawn is final).
    $flash = \App\Domains\FlashSales\Models\FlashSaleEvent::query()->create([
        'name' => "E2E vendor flash sale {$run}", 'display_name' => "E2E vendor flash sale {$run}", 'submission_starts_at' => now()->subHour(), 'submission_ends_at' => now()->addDays(3),
        'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6), 'max_qty_per_customer' => 2,
    ]);
    $flash->forceFill(['status' => 'submission_open'])->save();

    return [
        'run' => $run,
        'flash_event_id' => $flash->id,
        'ad_slot_id' => $slot->id,
        'store_id' => $store->id,
        'product' => ['id' => $product->id, 'title' => $product->title_en],
        'order_listing' => ['id' => $listing->id, 'sku' => $variant->sku],
        'fbn_listing' => ['id' => $fbnListing->id, 'sku' => $fbnVariant->sku],
        'warehouse' => ['id' => $warehouse->id, 'code' => $warehouse->code],
        'new_variants' => $newVariants,
        'to_ship' => ['id' => $toShip->id, 'number' => $toShip->number],
        'to_cancel' => ['id' => $toCancel->id, 'number' => $toCancel->number],
        'review' => ['id' => $review->id, 'title' => $review->title],
        'question' => ['id' => $question->id, 'body' => $question->body],
        'return' => ['id' => $return->id, 'number' => $return->number],
        'fresh' => ['email' => $fresh->email, 'store_id' => $freshStore->id],
        'acquirer' => ['code' => app(AcquirerReferrals::class)->code($admin), 'user_id' => $admin->id],
    ];
});

// Seller Agreement gate: published in Qatar only (no other Qatar store), so the uae e2e stores are never gated.
$out['gate'] = app(CountryContext::class)->runAs($qat, function () use ($qat, $run, $gateUser, $admin) {
    $template = ContractTemplate::query()->firstOrCreate(['country_id' => $qat->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'E2E Seller Agreement (Qatar)']);
    if ($template->publishedVersion === null) {
        $publisher = app(ContractPublisher::class);
        $publisher->publish($publisher->draft($template, ['body_en' => 'E2E Seller Agreement for the browser test. The store agrees to the Nawy marketplace terms.', 'body_ar' => 'اتفاقية البائع للاختبار']), $admin->id);
    }
    $store = Vendor::query()->firstOrCreate(['slug' => "e2e-ven-gate-{$run}"], [
        'owner_user_id' => $gateUser->id, 'type' => 'product', 'store_name' => "E2E Gate Store {$run}", 'status' => Vendor::STATUS_ACTIVE,
        'contact_phone' => '+97450000095', 'verified_at' => now(),
    ]);
    $gateUser->memberships()->firstOrCreate(
        ['role' => MembershipRole::ProductVendor, 'country_id' => $qat->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $store->id],
        ['status' => 'active'],
    );
    return ['email' => $gateUser->email, 'store_id' => $store->id, 'version_id' => $template->fresh()->publishedVersion?->id];
});

echo 'VENDOR_FIXTURES='.json_encode($out).PHP_EOL;
