<?php

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\CommissionRules;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An active listing with stock in the current country (creates seller, product, variant).
 *
 * @param  array<string, mixed>  $attributes
 */
function sellableListing(string $price = '100.00', int $stock = 10, array $attributes = [], ?Vendor $vendor = null): Listing
{
    $listing = Listing::factory()
        ->for($vendor ?? Vendor::factory()->create())
        ->active()
        ->create(['price' => $price] + $attributes);

    if ($stock > 0) {
        app(InventoryLedger::class)->move($listing, $stock, 'restock');
    }

    return $listing;
}

/**
 * A verified customer with a Sanctum token.
 *
 * @return array{0: User, 1: string}
 */
function customerWithToken(bool $verified = true): array
{
    $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);

    return [$user, $user->createToken('test')->plainTextToken];
}

/**
 * Absolute URL on a business panel host, e.g. onPanel('partner', 'orders').
 */
function onPanel(string $host, string $path = ''): string
{
    return "http://{$host}.".config('nawy.base_domain').'/'.ltrim($path, '/');
}

/**
 * A Seller Center user bound to a new active store in the given country
 * (membership vendor:product, tenant = the vendor in that country's database).
 *
 * @param  array<string, mixed>  $vendorAttributes
 * @return array{0: User, 1: Vendor}
 */
function sellerAccount(string $countryCode = 'uae', array $vendorAttributes = [], ?User $user = null): array
{
    $country = Country::query()->where('code', $countryCode)->sole();
    $user ??= User::factory()->create();
    $vendor = app(CountryContext::class)->runAs($country, fn (): Vendor => Vendor::factory()->create(
        ['owner_user_id' => $user->id, 'seller_agreement_accepted_at' => now()] + $vendorAttributes,
    ));

    $user->memberships()->create([
        'role' => MembershipRole::ProductVendor,
        'country_id' => $country->id,
        'tenant_type' => PanelCountries::VENDOR_TENANT,
        'tenant_id' => $vendor->id,
    ]);

    return [$user, $vendor];
}

/**
 * An admin: without a country = super admin (all countries); with a country
 * and a default role (AdminRoleSeeder) = that role in that country only.
 */
function adminAccount(?string $countryCode = null, ?string $role = null): User
{
    $user = User::factory()->create();
    $country = $countryCode === null ? null : Country::query()->where('code', $countryCode)->sole();
    $user->memberships()->create(['role' => MembershipRole::Admin, 'country_id' => $country?->id]);

    if ($role !== null) {
        test()->seed(AdminRoleSeeder::class);
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($country->id);
        $user->assignRole($role);
        setPermissionsTeamId($previousTeam);
        $user->unsetRelation('roles');
    }

    return $user;
}

/**
 * A sub-order of one item for the vendor in the current country, with the
 * units taken from stock as at checkout, placed `$placedAt` (default now)
 * and moved straight to `$status` (arranged directly).
 */
function subOrderFor(Vendor $vendor, SubOrderStatus $status = SubOrderStatus::Placed, int $quantity = 1, ?Listing $listing = null, mixed $placedAt = null): SubOrder
{
    $listing ??= sellableListing('100.00', stock: 5, vendor: $vendor);
    app(InventoryLedger::class)->move($listing, -$quantity, 'order_placed');
    $country = app(CountryContext::class)->current();
    $placedAt ??= now();

    $order = Order::query()->create([
        'number' => 'NW-'.strtoupper($country->iso2).'-'.fake()->unique()->numberBetween(1_000_000, 9_999_999),
        'customer_user_id' => 1,
        'status' => 'placed',
        'payment_method' => 'cod',
        'currency' => $country->currency,
        'subtotal' => bcmul((string) $listing->price, (string) $quantity, 4),
        'grand_total' => bcmul((string) $listing->price, (string) $quantity, 4),
        'shipping_address' => ['name' => 'Sara Ahmed', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2', 'phone' => '+971500000000'],
        'placed_at' => $placedAt,
    ]);
    $order->forceFill(['created_at' => $placedAt])->save();

    $subOrder = $order->subOrders()->create([
        'vendor_id' => $vendor->id,
        'number' => $order->number.'-1',
        'subtotal' => $order->subtotal,
        'total' => $order->grand_total,
    ]);
    $subOrder->forceFill(['created_at' => $placedAt])->save();
    $subOrder->items()->create([
        'listing_id' => $listing->id,
        'sku' => $listing->variant->sku,
        'title' => $listing->variant->product->title_en,
        'quantity' => $quantity,
        'unit_price' => $listing->price,
        'line_total' => $order->subtotal,
    ]);
    $subOrder->statusHistory()->create(['to_status' => SubOrderStatus::Placed, 'actor_type' => ActorType::Customer, 'actor_user_id' => 1]);

    if ($status !== SubOrderStatus::Placed) {
        $subOrder->forceFill(['status' => $status])->save();
        $subOrder->items()->update(['fulfillment_status' => $status->value]);
    }

    return $subOrder->fresh();
}

/**
 * Place an order through the API: one listing (new seller unless given) in
 * the cart, paid with `$method`. Returns the order, the customer and token.
 *
 * @param  array<string, mixed>  $listingAttributes
 * @return array{0: Order, 1: User, 2: string, 3: Listing}
 */
function placeOrderVia(string $method, string $price = '100.00', string $market = 'uae-en', int $quantity = 1, ?User $customer = null, ?Vendor $vendor = null, array $listingAttributes = []): array
{
    app('auth')->forgetGuards();
    $customer ??= User::factory()->create(['email_verified_at' => now()]);
    $token = $customer->createToken('test')->plainTextToken;
    $listing = sellableListing($price, stock: $quantity + 5, attributes: $listingAttributes, vendor: $vendor);
    test()->withToken($token)->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => $quantity])->assertOk();
    test()->withToken($token)->postJson("/api/v1/{$market}/orders", [
        'payment_method' => $method,
        'address' => ['name' => 'Sara Ahmed', 'phone' => checkoutPhoneFor($market), 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
    ])->assertCreated();
    app('auth')->forgetGuards();

    return [Order::query()->where('customer_user_id', $customer->id)->latest('id')->firstOrFail(), $customer, $token, $listing];
}

/**
 * A valid mobile number for the market's country (checkout validates the
 * inline address phone per country, QA-013).
 */
function checkoutPhoneFor(string $market): string
{
    return match (explode('-', $market)[0]) {
        'omn' => '+96891234567',
        default => '+971500000000',
    };
}

/**
 * The fake gateway reports the order's pending attempt captured.
 */
function captureOrderPayment(Order $order): Payment
{
    $payment = $order->payments()->latest('id')->firstOrFail();
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);

    return app(PaymentAttempts::class)->refresh($payment);
}

/**
 * Walk a sub-order through the machine up to `$to` (hooks run: COD
 * collection on delivery, settlement on completion).
 */
function moveSubOrderTo(SubOrder $subOrder, SubOrderStatus $to): SubOrder
{
    $path = [SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed, SubOrderStatus::Shipped, SubOrderStatus::Delivered, SubOrderStatus::Completed];

    foreach ($path as $step) {
        $subOrder->refresh();

        if (in_array($step, $subOrder->status->allowedNext(), true)) {
            app(SubOrderStateMachine::class)->transition($subOrder, $step, ActorType::Admin, null, 'test');
        }

        if ($step === $to) {
            break;
        }
    }

    return $subOrder->refresh();
}

/**
 * Give a customer spendable Nawy Credits (goodwill) in the current country.
 */
function giveCredits(int $customerUserId, string $amount): void
{
    $currency = app(CountryContext::class)->current()->currency;
    app(Wallets::class)->credit(
        $customerUserId,
        app(MoneyFactory::class)->of($amount, $currency),
        'test-credit:'.$customerUserId.':'.fake()->uuid(),
        'goodwill',
        AccountRef::platform(LedgerAccountCode::GoodwillExpense),
    );
}

/**
 * The whole ledger of the current country balances: every transaction's
 * debits equal its credits and every account projection equals its entries.
 */
function assertLedgerBalanced(): void
{
    $ledger = app(Ledger::class);

    foreach (LedgerTransaction::query()->with('entries')->get() as $transaction) {
        expect(bccomp(
            (string) $transaction->entries->where('direction', 'debit')->reduce(fn (string $sum, $entry) => bcadd($sum, (string) $entry->amount, 4), '0'),
            (string) $transaction->entries->where('direction', 'credit')->reduce(fn (string $sum, $entry) => bcadd($sum, (string) $entry->amount, 4), '0'),
            4,
        ))->toBe(0, "Transaction {$transaction->idempotency_key} is unbalanced");
    }

    foreach (LedgerAccount::query()->get() as $account) {
        expect((string) $ledger->balanceFromEntries($account)->getAmount())
            ->toBe((string) app(MoneyFactory::class)->of((string) $account->balance, $account->currency)->getAmount());
    }
}

/**
 * Balance of a ledger account as a plain string in the current currency.
 */
function ledgerBalance(AccountRef $ref): string
{
    return (string) app(Ledger::class)->balance($ref, app(CountryContext::class)->current()->currency)->getAmount();
}

/**
 * A marketer in the current country (user + profile + one membership per
 * job type), active by default.
 *
 * @param  array<int, string>  $jobTypes
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: MarketerProfile}
 */
function marketerAccount(array $jobTypes = ['affiliate'], string $status = 'active', array $attributes = []): array
{
    $user = User::factory()->create();
    $marketer = app(MarketerProfiles::class)->register($user, $jobTypes, ['whatsapp_phone' => '+971500000001'] + $attributes);
    $marketer->forceFill(['global_status' => $status] + array_intersect_key($attributes, array_flip(['followers', 'is_verified', 'vendor_id', 'agency_id'])))->save();

    return [$user, $marketer->refresh()];
}

/**
 * Commission defaults of the current country (platform rule without category).
 *
 * @param  array<string, string|null>  $values
 */
function marketerDefaults(array $values): void
{
    app(CommissionRules::class)->save(null, null, $values);
}

/**
 * A vendor campaign through the wizard (origin A), approved by an admin
 * unless `$live` is false. The listing gets plenty of stock.
 *
 * @param  array<string, mixed>  $data
 */
function liveCampaign(Vendor $vendor, ?Listing $listing = null, array $data = [], bool $live = true): MarketerCampaign
{
    $listing ??= sellableListing('105.00', stock: 50, vendor: $vendor);
    $campaign = app(CampaignWizard::class)->create(
        CampaignOrigin::Vendor,
        $data + ['listing_id' => $listing->id, 'title' => 'Launch', 'budget' => '1000.00', 'open_to_affiliates' => true],
        $vendor,
        null,
    );

    if ($live) {
        app(CampaignStateMachine::class)->approve($campaign, 1);
    }

    return $campaign->refresh();
}

/**
 * Email system: the catalogue emails actually delivered in this test
 * (MAIL_MAILER=array), filtered by event and optionally recipient. Mail::fake()
 * cannot see a Mailable returned from a notification, so tests read the
 * transport instead.
 *
 * @return Collection<int, Email>
 */
function sentCatalogueEmails(string $event, ?string $to = null): Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
        ->filter(fn (Email $mail): bool => $mail->getHeaders()->get('X-Nawy-Event')?->getBodyAsString() === $event
            && ($to === null || collect($mail->getTo())->contains(fn (Address $address): bool => $address->getAddress() === $to)))
        ->values();
}
