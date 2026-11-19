<?php

namespace Database\Seeders;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\ShipmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Enums\StockBucket;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\InboundShipments;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Migration\Support\LegacyRedirects;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Test records for the Playwright end-to-end run (WPT). Idempotent: users,
 * stores, the carrier and the driver are created once; the records each panel
 * acts on (a pending listing, a placed and a packed sub-order, a trip, FBN
 * stock and a submitted inbound plan) are
 * topped up on every run so a run never depends on the previous one.
 *
 * The ids the e2e specs act on go to storage/app/private/e2e-fixtures.json.
 *
 * Every record is marked `e2e` (emails `e2e-*@nawy.test`, slugs `e2e-*`).
 * Refuses to run in production. Credentials are test values only, mirrored in
 * website/e2e/support/accounts.ts.
 *
 *   php artisan db:seed --class=E2eSeeder
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'E2e-Passw0rd!';

    public const COUNTRY = 'uae';

    public function run(CountryContext $context): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('E2eSeeder refuses to run in production.');
        }

        $country = Country::query()->where('code', self::COUNTRY)->sole();

        $admin = $this->user('e2e-admin@nawy.test', 'E2E Admin', twoFactor: true);
        $admin->memberships()->firstOrCreate(['role' => MembershipRole::Admin, 'country_id' => null], ['status' => 'active']);

        $customer = $this->user('e2e-customer@nawy.test', 'E2E Customer');
        $customer->memberships()->firstOrCreate(['role' => MembershipRole::Customer, 'country_id' => $country->id], ['status' => 'active']);

        // Forgot-password flow: resetting revokes tokens, so it has its own account.
        $resetUser = $this->user('e2e-reset@nawy.test', 'E2E Reset');
        $resetUser->memberships()->firstOrCreate(['role' => MembershipRole::Customer, 'country_id' => $country->id], ['status' => 'active']);

        $seller = $this->user('e2e-seller@nawy.test', 'E2E Seller', twoFactor: true);
        $travel = $this->user('e2e-travel@nawy.test', 'E2E Travel', twoFactor: true);
        $carrierUser = $this->user('e2e-carrier@nawy.test', 'E2E Carrier', twoFactor: true);
        $driverUser = $this->user('e2e-driver@nawy.test', 'E2E Driver', twoFactor: true);
        $marketerUser = $this->user('e2e-marketer@nawy.test', 'E2E Marketer', twoFactor: true);

        $fixtures = $context->runAs($country, function () use ($country, $customer, $seller, $travel, $carrierUser, $driverUser, $marketerUser): array {
            $store = $this->vendor($seller, 'product', 'e2e-store', 'E2E Store');
            $seller->memberships()->firstOrCreate(
                ['role' => MembershipRole::ProductVendor, 'country_id' => $country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $store->id],
                ['status' => 'active'],
            );

            $agency = $this->vendor($travel, 'travel', 'e2e-travel-agency', 'E2E Travel Agency');
            $travel->memberships()->firstOrCreate(
                ['role' => MembershipRole::TravelAgency, 'country_id' => $country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id],
                ['status' => 'active'],
            );

            $this->acceptSellerAgreement($store, $seller);
            $this->acceptSellerAgreement($agency, $travel);

            $carrier = Carrier::query()->firstOrCreate(['code' => 'E2ECOURIERS'], ['name' => 'E2E Couriers', 'status' => 'active', 'owner_user_id' => $carrierUser->id]);
            $carrierUser->memberships()->firstOrCreate(
                ['role' => MembershipRole::CarrierSupervisor, 'country_id' => $country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id],
                ['status' => 'active'],
            );
            $driverUser->memberships()->firstOrCreate(
                ['role' => MembershipRole::DeliveryAgent, 'country_id' => $country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id],
                ['status' => 'active'],
            );
            DeliveryAgent::query()->firstOrCreate(['user_id' => $driverUser->id], ['carrier_id' => $carrier->id, 'name' => $driverUser->name, 'status' => 'active']);

            $active = $this->listing($store, ListingStatus::Active, 50);
            $pending = $this->pendingListing($store);
            $placed = $this->subOrder($store, $customer, $active, SubOrderStatus::Placed);
            // The carrier's pickup list is oldest first: reuse a parcel still waiting for a driver.
            $shipment = Shipment::query()->where('carrier_id', $carrier->id)->where('status', ShipmentStatus::LabelCreated)
                ->whereDoesntHave('assignments')->orderBy('id')->first()
                ?? app(DeliveryDispatch::class)->allocate($this->subOrder($store, $customer, $active, SubOrderStatus::Packed), $carrier, ActorType::Admin, null);
            $packed = $shipment->subOrder()->with('order')->sole();
            // Return request and review flows need a delivered shipment of the customer.
            $delivered = $this->subOrder($store, $customer, $active, SubOrderStatus::Delivered);
            $delivered->forceFill(['delivered_at' => now()])->save();

            return [
                'country' => self::COUNTRY,
                'store_slug' => $store->slug,
                'active_listing_id' => $active->id,
                'pending_listing_id' => $pending->id,
                'placed_sub_order' => ['id' => $placed->id, 'number' => $placed->number, 'order' => $placed->order->number],
                'packed_sub_order' => ['id' => $packed->id, 'number' => $packed->number, 'order' => $packed->order->number],
                'shipment' => ['id' => $shipment->id, 'number' => $shipment->number],
                'delivered_sub_order' => ['id' => $delivered->id, 'number' => $delivered->number, 'order' => $delivered->order->number],
                'trip_slug' => $tripSlug = $this->trip($agency),
                'promoted_trip_slug' => $this->promote($agency, $tripSlug),
                'referral_code' => $this->marketer($marketerUser, $store, $active),
                'fbn' => $this->fbn($store, $seller),
            ];
        });
        $fixtures['legacy_redirect'] = $this->legacyRedirect();

        Storage::disk('local')->put('e2e-fixtures.json', (string) json_encode($fixtures, JSON_PRETTY_PRINT));
        $this->command?->info('E2E records ready: '.Storage::disk('local')->path('e2e-fixtures.json'));
    }

    /**
     * WP11 extras: one deterministic old-storefront URL in the 301 map (the
     * same entry legacy:redirects builds for every market), so the website
     * redirect is testable without a migration run. Idempotent.
     *
     * @return array{from: string, to: string}
     */
    private function legacyRedirect(): array
    {
        $from = '/'.self::COUNTRY.'-en/live-streams';
        $to = '/'.self::COUNTRY.'-en/live';
        DB::table('legacy_redirects')->upsert(
            [['from_path' => $from, 'to_path' => $to, 'status' => 301, 'source' => 'storefront', 'country_code' => self::COUNTRY, 'created_at' => now(), 'updated_at' => now()]],
            ['from_path'],
            ['to_path', 'status', 'source', 'country_code', 'updated_at'],
        );
        LegacyRedirects::flush();

        return ['from' => $from, 'to' => $to];
    }

    private function user(string $email, string $name, bool $twoFactor = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => self::PASSWORD,
            'two_factor_enabled' => $twoFactor,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status' => 'active',
        ])->save();

        return $user;
    }

    private function vendor(User $owner, string $type, string $slug, string $name): Vendor
    {
        return Vendor::query()->firstOrCreate(['slug' => $slug], [
            'owner_user_id' => $owner->id,
            'type' => $type,
            'store_name' => $name,
            'status' => Vendor::STATUS_ACTIVE,
            'contact_phone' => '+971500000099',
            'verified_at' => now(),
            'seller_agreement_accepted_at' => now(),
        ]);
    }

    /**
     * The store signs the Seller Agreement in force in the country (if one is
     * published), so the panel never stops the run at "Sign the updated Seller
     * Agreement". Signing is idempotent.
     */
    private function acceptSellerAgreement(Vendor $store, User $owner): void
    {
        $gates = app(ContractGates::class);
        $version = $gates->sellerAgreement();

        if ($version !== null && ! $gates->sellerAgreementAccepted($store)) {
            app(ContractSigner::class)->sign($version, SignerType::Vendor, $store->id, $owner->id, $owner->name, SignatureMethod::Click, '127.0.0.1', 'E2eSeeder');
        }
    }

    /** A listing of the store on an existing catalogue variant (no new categories in the dev catalogue). */
    private function listing(Vendor $store, ListingStatus $status, int $stock, int $offset = 0): Listing
    {
        $existing = Listing::query()->withAvailableQuantity()->where('vendor_id', $store->id)->orderBy('id')->skip($offset)->first();
        if ($existing !== null) {
            // Every run sells a few units: keep the stock topped up.
            if ($stock > 0 && (int) $existing->available_quantity < $stock) {
                app(InventoryLedger::class)->move($existing, $stock - (int) $existing->available_quantity, 'restock');
            }

            return $existing;
        }

        $variantId = DB::connection(app(CountryContext::class)->connectionName())->table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.status', 'active')
            ->orderBy('v.id')
            ->skip($offset)
            ->value('v.id');

        $listing = Listing::factory()->for($store)->create(['product_variant_id' => $variantId, 'status' => $status, 'price' => '120.0000']);
        $listing->statusHistory()->create(['from_status' => null, 'to_status' => $status, 'actor_user_id' => null]);
        if ($stock > 0) {
            app(InventoryLedger::class)->move($listing, $stock, 'restock');
        }

        return $listing;
    }

    /**
     * Fulfilled by Nawy: an e2e warehouse, one FBN listing of the store on its
     * own e2e variant with at least 20 units available there, and a submitted
     * inbound plan waiting to ship (a new one when the last was moved on).
     *
     * @return array{warehouse_code: string, listing_id: int, available: int, inbound: array{id: int, number: string}}
     */
    private function fbn(Vendor $store, User $seller): array
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'E2E-FC'], [
            'name_en' => 'E2E Fulfilment Centre', 'name_ar' => 'مركز استيفاء الاختبار', 'address' => 'E2E logistics park', 'city' => 'Dubai', 'capacity_cubic_feet' => '10000', 'is_active' => true,
        ]);

        $product = Product::query()->where('status', 'active')->orderBy('id')->firstOrFail();
        $variant = ProductVariant::query()->firstOrCreate(['sku' => 'E2E-FBN-1'], ['product_id' => $product->id, 'weight_grams' => 400]);
        $listing = Listing::query()->firstOrCreate(
            ['vendor_id' => $store->id, 'product_variant_id' => $variant->id, 'condition' => 'new'],
            ['price' => '95.0000', 'fulfilment' => 'fbm', 'handling_days' => 1],
        );

        if ($listing->status !== ListingStatus::Active && $listing->status !== ListingStatus::OutOfStock) {
            $from = $listing->status;
            $listing->forceFill(['status' => ListingStatus::Active])->save();
            $listing->statusHistory()->create(['from_status' => $from, 'to_status' => ListingStatus::Active, 'actor_user_id' => null, 'reason' => 'e2e FBN listing']);
        }

        app(FbnEnrolment::class)->enrol($listing, null);
        $stock = app(WarehouseStockService::class);
        $available = $stock->stockFor($warehouse, $listing)->available();

        if ($available < 20) {
            $stock->adjust($warehouse, $listing->load('variant'), StockBucket::OnHand, 20 - $available, 'correction', 'E2E top-up', null);
        }

        $inbound = InboundShipment::query()->where('vendor_id', $store->id)->where('status', InboundStatus::Submitted)->latest('id')->first();

        if ($inbound === null) {
            $machine = app(InboundShipments::class);
            $inbound = $machine->submit($machine->draft($store, $warehouse->id, [['listing_id' => $listing->id, 'quantity' => 12, 'cartons' => 2]], ['expected_at' => now()->addDays(3)->toDateString()], $seller->id), $seller->id);
        }

        return [
            'warehouse_code' => $warehouse->code,
            'listing_id' => $listing->id,
            'available' => $stock->stockFor($warehouse, $listing)->available(),
            'inbound' => ['id' => $inbound->id, 'number' => (string) $inbound->number],
        ];
    }

    /** Admin "approve a listing": the second listing goes back to pending review on every run. */
    private function pendingListing(Vendor $store): Listing
    {
        $listing = $this->listing($store, ListingStatus::PendingReview, 5, offset: 1);
        if ($listing->status !== ListingStatus::PendingReview) {
            $from = $listing->status;
            $listing->forceFill(['status' => ListingStatus::PendingReview])->save();
            $listing->statusHistory()->create(['from_status' => $from, 'to_status' => ListingStatus::PendingReview, 'actor_user_id' => null, 'reason' => 'e2e reset']);
        }

        return $listing;
    }

    /** A one-item COD sub-order of the store, arranged directly at `$status`. */
    private function subOrder(Vendor $store, User $customer, Listing $listing, SubOrderStatus $status): SubOrder
    {
        $country = app(CountryContext::class)->current();
        app(InventoryLedger::class)->move($listing, -1, 'order_placed');

        $order = Order::query()->create([
            'number' => 'NW-'.strtoupper($country->iso2).'-E2E'.strtoupper(Str::random(6)),
            'customer_user_id' => $customer->id,
            'status' => 'placed',
            'payment_method' => 'cod',
            'currency' => $country->currency,
            'subtotal' => $listing->price,
            'grand_total' => $listing->price,
            'shipping_address' => ['name' => 'E2E Customer', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'E2E Tower', 'phone' => '+971500000098'],
            'placed_at' => now(),
        ]);

        $subOrder = $order->subOrders()->create([
            'vendor_id' => $store->id,
            'number' => $order->number.'-1',
            'subtotal' => $order->subtotal,
            'total' => $order->grand_total,
        ]);
        $subOrder->items()->create([
            'listing_id' => $listing->id,
            'sku' => $listing->variant->sku,
            'title' => $listing->variant->product->title_en,
            'quantity' => 1,
            'unit_price' => $listing->price,
            'line_total' => $listing->price,
        ]);
        $subOrder->statusHistory()->create(['to_status' => SubOrderStatus::Placed, 'actor_type' => ActorType::Customer, 'actor_user_id' => $customer->id]);

        if ($status !== SubOrderStatus::Placed) {
            $subOrder->forceFill(['status' => $status])->save();
            $subOrder->items()->update(['fulfillment_status' => $status->value]);
        }

        return $subOrder->fresh();
    }

    /** A copy of the first active trip, owned by the e2e agency, with open departures in the future. */
    private function trip(Vendor $agency): ?string
    {
        $existing = TravelPackage::query()->where('vendor_id', $agency->id)->value('slug');
        if ($existing !== null) {
            return $existing;
        }

        $source = TravelPackage::query()->where('status', 'active')->whereHas('departures')->with('departures.rooms')->orderBy('id')->first();
        if ($source === null) {
            $this->command?->warn('No active trip to copy; the travel booking flow will be skipped.');

            return null;
        }

        $copy = $source->replicate(['contract_path', 'cover_path', 'gallery']);
        $copy->forceFill(['vendor_id' => $agency->id, 'slug' => 'e2e-trip-'.Str::lower(Str::random(6)), 'title_en' => 'E2E Trip', 'title_ar' => 'رحلة اختبار'])->save();

        foreach ($source->departures as $i => $departure) {
            $newDeparture = $departure->replicate();
            $newDeparture->forceFill([
                'travel_package_id' => $copy->id,
                'departs_on' => now()->addDays(60 + 30 * $i)->toDateString(),
                'returns_on' => now()->addDays(60 + 30 * $i + (int) $source->nights)->toDateString(),
                'seat_capacity' => 500,
                'seats_reserved' => 0,
                'status' => 'open',
            ])->save();
            foreach ($departure->rooms as $room) {
                $newRoom = $room->replicate();
                $newRoom->forceFill(['travel_departure_id' => $newDeparture->id])->save();
            }
        }

        return $copy->slug;
    }

    /**
     * D14: the e2e trip is promoted (paid window around now), so lists show
     * it first with the Sponsored label.
     */
    private function promote(Vendor $agency, ?string $slug): ?string
    {
        $package = $slug === null ? null : TravelPackage::query()->where('vendor_id', $agency->id)->where('slug', $slug)->first();
        if ($package === null || $package->status !== PackageStatus::Active) {
            return null;
        }

        $slot = app(TravelPromotions::class)->slot();
        $slot->forceFill(['is_active' => true])->save();
        $product = TravelPromotionProduct::query()->firstOrCreate(['code' => 'travel-7d'], ['ad_slot_id' => $slot->id, 'name_en' => '7 days at the top', 'name_ar' => '7 أيام في الصدارة', 'duration_days' => 7, 'price' => '150.0000']);
        $promotion = TravelPromotion::query()->firstOrNew(['travel_package_id' => $package->id, 'number' => 'TP-E2E-'.$package->id]);
        $promotion->fill([
            'vendor_id' => $agency->id, 'travel_promotion_product_id' => $product->id, 'ad_slot_id' => $slot->id, 'duration_days' => 7,
            'currency' => app(CountryContext::class)->current()->currency, 'amount' => $product->price, 'bought_by_user_id' => $agency->owner_user_id ?? 0,
        ])->forceFill(['status' => TravelPromotionStatus::Active, 'paid_at' => now(), 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()])->save();

        return $package->slug;
    }

    /** An approved marketer with one accepted campaign (a referral link to copy). */
    private function marketer(User $user, Vendor $store, Listing $listing): ?string
    {
        $profile = MarketerProfile::query()->where('user_id', $user->id)->first()
            ?? app(MarketerProfiles::class)->register($user, ['affiliate'], ['whatsapp_phone' => '+971500000097', 'display_name' => 'E2E Marketer']);
        $profile->forceFill(['global_status' => MarketerStatus::Active])->save();

        $accepted = CampaignInvitation::query()->where('marketer_profile_id', $profile->id)->where('status', InvitationStatus::Accepted)->value('referral_code');
        if ($accepted !== null) {
            return $accepted;
        }

        $campaign = MarketerCampaign::query()->where('listing_id', $listing->id)->first();
        if ($campaign === null) {
            $campaign = app(CampaignWizard::class)->create(
                CampaignOrigin::Vendor,
                ['listing_id' => $listing->id, 'title' => 'E2E Launch', 'budget' => '1000.00', 'open_to_affiliates' => true],
                $store,
                null,
            );
            app(CampaignStateMachine::class)->approve($campaign, 1);
        }

        return app(Invitations::class)->joinOpen($campaign->refresh(), $profile)->referral_code;
    }
}
