<?php

use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Models\AdEvent;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Customers\Enums\CustomerAccountStatus;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Support\Disputes;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Support\Social\FakeSocialProvider;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    Notification::fake();
});

describe('QC-015 scheduled jobs', function () {
    it('registers the new jobs at their SRS times', function () {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event): array => [trim(Str::after($event->command, 'artisan'), " '") => $event->expression]);

        expect($events['nawy:coupons:expire'])->toBe('15 0 * * *')
            ->and($events['nawy:analytics:aggregate'])->toBe('0 * * * *')
            ->and($events['nawy:analytics:prune'])->toBe('30 4 * * *')
            ->and($events['nawy:catalog:rank-bestsellers'])->toBe('30 3 * * 1');
    });

    it('switches off ended coupons and ends their active participation invitations', function () {
        $ended = Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subMinute(), 'is_active' => true]);
        $live = Coupon::factory()->create(['code' => 'LIVE', 'ends_at' => now()->addDay(), 'is_active' => true]);
        $invitation = CouponParticipationInvitation::query()->create(['coupon_id' => $ended->id, 'title' => 'Eid', 'currency' => 'AED', 'min_fee' => '10', 'capacity' => 5, 'closes_at' => now()->subDays(3)]);
        $invitation->forceFill(['status' => ParticipationInvitationStatus::Active])->save();

        $this->artisan('nawy:coupons:expire')->assertSuccessful();
        $this->artisan('nawy:coupons:expire')->assertSuccessful();

        expect($ended->refresh()->is_active)->toBeFalse()->and($live->refresh()->is_active)->toBeTrue()
            ->and($invitation->refresh()->status)->toBe(ParticipationInvitationStatus::Ended)
            ->and(StateTransition::query()->where('subject_type', 'coupon_participation_invitation')->where('to_status', 'ended')->count())->toBe(1);
    });

    it('rolls orders, GMV and ad events up per day and prunes old raw events', function () {
        [$order] = placeOrderVia('cod', '100.00');
        $listing = sellableListing('50.00');
        $campaign = app(AdCampaigns::class)->create($listing->vendor, ['listing_id' => $listing->id, 'pricing_model' => 'cpc', 'bid' => '2.00', 'daily_budget' => '10.00'], 1);
        DB::connection(app(CountryContext::class)->connectionName())->table('ad_events')->insert([
            ['ad_campaign_id' => $campaign->id, 'type' => 'impression', 'placement' => 'search', 'cost' => 0, 'is_billable' => true, 'created_at' => now()],
            ['ad_campaign_id' => $campaign->id, 'type' => 'click', 'placement' => 'search', 'cost' => '1.5', 'is_billable' => true, 'created_at' => now()],
            ['ad_campaign_id' => $campaign->id, 'type' => 'click', 'placement' => 'search', 'cost' => '1.5', 'is_billable' => true, 'created_at' => now()->subDays(400)],
        ]);

        $this->artisan('nawy:analytics:aggregate')->assertSuccessful();
        $this->artisan('nawy:analytics:aggregate')->assertSuccessful();

        $metrics = DB::connection(app(CountryContext::class)->connectionName())->table('analytics_daily_metrics')
            ->where('day', now()->toDateString())->get()->mapWithKeys(fn ($row): array => [$row->metric.($row->dimension === '' ? '' : '|'.$row->dimension) => (string) $row->value]);
        expect($metrics['orders.count'])->toBe('1.0000')
            ->and($metrics['orders.gmv|AED'])->toBe((string) $order->grand_total)
            ->and($metrics['ads.impressions'])->toBe('1.0000')
            ->and($metrics['ads.clicks'])->toBe('1.0000')
            ->and($metrics['ads.spend'])->toBe('1.5000');

        $this->artisan('nawy:analytics:prune')->assertSuccessful();
        expect(AdEvent::query()->count())->toBe(2);
    });

    it('ranks bestsellers by units sold in 30 days and sorts the storefront by it', function () {
        [$first] = placeOrderVia('cod', '50.00', quantity: 3);
        [$second] = placeOrderVia('cod', '60.00', quantity: 1);
        $unsold = sellableListing('70.00');

        $this->artisan('nawy:catalog:rank-bestsellers')->assertSuccessful();

        $top = $first->subOrders()->sole()->items()->sole()->listing->variant->product;
        expect($top->refresh()->bestseller_rank)->toBe(1)->and($top->units_sold_30d)->toBe(3)
            ->and($unsold->variant->product->refresh()->bestseller_rank)->toBeNull();

        $this->getJson('/api/v1/uae-en/products?sort=bestselling')->assertOk()
            ->assertJsonPath('data.0.slug', $top->slug)
            ->assertJsonPath('data.1.slug', $second->subOrders()->sole()->items()->sole()->listing->variant->product->slug);
    });
});

describe('QC-016 lifecycles', function () {
    it('moves products through catalogue §1.2 states with guards and history', function () {
        $product = sellableListing()->variant->product;
        $admin = adminAccount();
        $form = fn (string $status, ?string $reason = null): array => [
            'title_en' => $product->title_en, 'title_ar' => 'عنوان', 'slug' => $product->slug, 'category_id' => $product->category_id,
            'status' => $status, 'status_reason' => $reason, 'fulfilment_mode' => 'ready_to_ship',
        ];

        $this->actingAs($admin)->put(onPanel('admin', "catalog/products/{$product->id}"), $form('restricted'))->assertSessionHasErrors('status');
        $this->put(onPanel('admin', "catalog/products/{$product->id}"), $form('draft'))->assertSessionHasErrors('status');
        $this->put(onPanel('admin', "catalog/products/{$product->id}"), $form('restricted', 'Recall notice'))->assertSessionHasNoErrors();
        $this->put(onPanel('admin', "catalog/products/{$product->id}"), $form('discontinued'))->assertSessionHasNoErrors();
        $this->put(onPanel('admin', "catalog/products/{$product->id}"), $form('active'))->assertSessionHasErrors('status');

        expect(Product::query()->find($product->id)->status)->toBe('discontinued')
            ->and(StateTransition::query()->where('subject_type', 'product')->pluck('to_status')->all())->toBe(['restricted', 'discontinued']);
        $this->getJson('/api/v1/uae-en/products/'.$product->slug)->assertNotFound();
    });

    it('moves vendor accounts through Appendix A states; account managers cannot blacklist', function () {
        $vendor = Vendor::factory()->create();
        $admin = adminAccount();

        $this->actingAs($admin)->post(onPanel('admin', "vendors/{$vendor->id}/status"), ['status' => 'on_hold', 'reason' => 'KYC review'])->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "vendors/{$vendor->id}/status"), ['status' => 'active', 'reason' => 'KYC done'])->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "vendors/{$vendor->id}/status"), ['status' => 'blacklisted', 'reason' => 'Fraud'])->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "vendors/{$vendor->id}/status"), ['status' => 'active', 'reason' => 'Appeal'])->assertSessionHasErrors('status');

        expect($vendor->refresh()->status)->toBe('blacklisted')
            ->and(StateTransition::query()->where('subject_type', 'vendor')->pluck('to_status')->all())->toBe(['on_hold', 'active', 'blacklisted']);

        $manager = adminAccount('uae', 'account_manager');
        $managed = Vendor::factory()->create(['account_manager_id' => $manager->id]);
        $this->actingAs($manager)->post(onPanel('admin', "vendors/{$managed->id}/status"), ['status' => 'blacklisted', 'reason' => 'Fraud'])->assertForbidden();
    });

    it('suspends and bans customer accounts with history, signing them out and refusing sign-in', function () {
        [$customer, $token] = customerWithToken();
        $customer->memberships()->firstOrCreate(['role' => MembershipRole::Customer, 'country_id' => app(CountryContext::class)->current()->id]);
        $support = adminAccount('uae', 'support');

        $this->actingAs($support)->get(onPanel('admin', 'customers?q='.urlencode($customer->email)))->assertOk()->assertSee('data-customer="'.$customer->id.'"', false);
        $this->post(onPanel('admin', "customers/{$customer->id}/status"), ['status' => 'suspended'])->assertSessionHasErrors('reason');
        // Browser test BT-ADM-001: the refusal is shown on the list, and the GET filter keeps its own value.
        $this->from(onPanel('admin', 'customers?status=active'))->followingRedirects()
            ->post(onPanel('admin', "customers/{$customer->id}/status"), ['status' => 'suspended'])
            ->assertOk()->assertSee('role="alert"', false)->assertSee('The reason field is required.')
            ->assertSee('<option value="active" selected', false);
        $this->post(onPanel('admin', "customers/{$customer->id}/status"), ['status' => 'suspended', 'reason' => 'Chargeback abuse'])->assertSessionHasNoErrors();
        $this->actingAs(adminAccount('uae', 'finance'))->post(onPanel('admin', "customers/{$customer->id}/status"), ['status' => 'active', 'reason' => 'x x x'])->assertForbidden();

        expect($customer->refresh()->status)->toBe(CustomerAccountStatus::Suspended->value)
            ->and($customer->tokens()->count())->toBe(0)
            ->and(DB::table('user_status_transitions')->where('user_id', $customer->id)->value('actor_user_id'))->toBe($support->id);

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/uae-en/me')->assertUnauthorized();
        $this->postJson('/api/v1/uae-en/auth/social/google/token', ['id_token' => FakeSocialProvider::token(['sub' => 'g-1', 'email' => $customer->email, 'email_verified' => true]), 'device_name' => 'x'])
            ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    });
});

it('QC-045: the Support role approves disputes, returns and warranty claims, as the matrix grants', function () {
    $support = adminAccount('uae', 'support');
    expect($support->can('disputes.manage'))->toBeTrue()
        ->and($support->can('returns.manage'))->toBeTrue()
        ->and($support->can('warranty_claims.manage'))->toBeTrue();

    [, $vendor] = sellerAccount();
    [$order, $customer] = placeOrderVia('card', '100.00', vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = moveSubOrderTo($order->subOrders()->sole(), SubOrderStatus::Delivered);
    $dispute = app(Disputes::class)->open($subOrder, $customer->id, DisputeReason::cases()[0], 'Broken.');

    $this->actingAs($support)->post(onPanel('admin', "disputes/{$dispute->id}/review"))->assertRedirect();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::UnderReview);
});

it('QA-045: the Horizon dashboard needs an admin even when running locally', function () {
    app()->detectEnvironment(fn (): string => 'local');
    $check = function (?User $user): bool {
        $user === null ? auth()->logout() : $this->actingAs($user);
        $request = Request::create('/horizon/api/jobs/pending');
        $request->setUserResolver(fn () => $user);

        return Horizon::check($request);
    };

    expect($check(null))->toBeFalse()
        ->and($check(User::factory()->create()))->toBeFalse()
        ->and($check(adminAccount()))->toBeTrue();
});

it('QC-024 / QC-025: the admin dashboard shows KPIs by country with a CSV export, in a shell with a mobile menu', function () {
    placeOrderVia('cod', '100.00');
    $admin = adminAccount('uae', 'operations');

    $this->actingAs($admin)->get(onPanel('admin'))->assertOk()
        ->assertSee('data-kpis-by-country', false)->assertSee('data-country="uae"', false)
        ->assertSee('GMV today')->assertSee('Orders today')
        ->assertSee('data-sidebar-toggle', false)->assertSee('data-panel-user', false);

    $csv = $this->get(onPanel('admin', '?export=csv'))->assertOk()->streamedContent();
    expect($csv)->toContain('United Arab Emirates')->toContain(',1,');

    $this->actingAs(adminAccount('uae', 'account_manager'))->get(onPanel('admin'))->assertOk()->assertDontSee('GMV today');
});
