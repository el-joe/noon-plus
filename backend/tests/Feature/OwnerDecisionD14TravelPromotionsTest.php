<?php

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Content\Support\BlockResolver;
use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Owner decision D14 (2026-11-18): agencies list trips for free and may pay
| Nawy by card for a promotion that shows a trip first, labelled
| "Sponsored", in travel lists, search and page-builder travel blocks.
| Customer bookings stay payment-free (D8); no commission (D11).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Notification::fake();
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * An agency owner signed up in the country (Travel Partner member).
 *
 * @return array{0: User, 1: Vendor}
 */
function promoAgency(string $country = 'uae'): array
{
    $user = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'owner_user_id' => $user->id]);
    $user->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => Country::query()->where('code', $country)->value('id'), 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id]);

    return [$user, $agency];
}

/**
 * A trip of the agency in the current country (approved `$approvedDaysAgo` days ago).
 */
function promoTrip(Vendor $agency, string $title, PackageStatus $status = PackageStatus::Active, int $approvedDaysAgo = 1, array $attributes = []): TravelPackage
{
    $package = new TravelPackage($attributes + ['vendor_id' => $agency->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)), 'title_en' => $title, 'title_ar' => $title.' AR', 'trip_type' => 'leisure', 'destination_city' => 'Baku', 'nights' => 4]);
    $package->forceFill(['status' => $status, 'approved_at' => now()->subDays($approvedDaysAgo)])->save();

    return $package;
}

function promoProduct(string $price = '105.00', int $days = 7): TravelPromotionProduct
{
    return TravelPromotionProduct::query()->create(['ad_slot_id' => app(TravelPromotions::class)->slot()->id, 'code' => "travel-{$days}d-".Str::random(3), 'name_en' => "{$days} days at the top", 'name_ar' => "{$days} أيام", 'duration_days' => $days, 'price' => $price]);
}

/**
 * Buy and capture a promotion through the fake gateway.
 */
function promoPaid(Vendor $agency, TravelPackage $package, ?TravelPromotionProduct $product = null): TravelPromotion
{
    $user = User::query()->findOrFail($agency->owner_user_id);
    [$promotion, $payment] = app(TravelPromotions::class)->buy($agency, $package, $product ?? promoProduct(), $user);
    FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
    app(PaymentAttempts::class)->refresh($payment);

    return $promotion->refresh();
}

function promoSlugs(string $uri): array
{
    return collect(test()->getJson($uri)->assertOk()->json('data'))->map(fn (array $card): string => $card['slug'].($card['sponsored'] ? '*' : ''))->all();
}

describe('purchase, activation and expiry', function () {
    it('buys a promotion on the panel, pays by card through the gateway and goes live for the paid window', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Baku 4 nights');
        $product = promoProduct('105.00', 7);

        $this->actingAs($owner)->get(onPanel('travel-agency', 'promotions/create'))->assertOk()->assertSee('Baku 4 nights')->assertSee('7 days at the top');
        $redirect = $this->actingAs($owner)->post(onPanel('travel-agency', 'promotions'), ['package_id' => $trip->id, 'product_id' => $product->id])->assertRedirect()->headers->get('Location');

        $promotion = TravelPromotion::query()->sole();
        $payment = Payment::query()->where('payable_type', TravelPromotion::PAYABLE)->sole();
        expect($redirect)->toBe($payment->redirect_url)
            ->and($promotion->status)->toBe(TravelPromotionStatus::PendingPayment)
            ->and((string) $promotion->amount)->toBe('105.0000')
            ->and($payment->payable_id)->toBe($promotion->id)
            ->and($payment->method->isGateway())->toBeTrue()
            ->and($payment->order_id)->toBeNull();

        // Not live before payment.
        expect(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$trip->slug]);

        // Gateway return → verified server-side → back to the Travel Partner panel.
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $back = $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")->assertRedirect()->headers->get('Location');
        expect($back)->toStartWith(route('travel_agency.promotions.return'))->toContain($payment->reference);
        $this->actingAs($owner)->get($back)->assertRedirect(route('travel_agency.promotions'))->assertSessionHas('status');

        $promotion->refresh();
        expect($promotion->status)->toBe(TravelPromotionStatus::Active)
            ->and((string) $promotion->vat_amount)->toBe('5.0000')
            ->and($promotion->ends_at->diffInDays($promotion->starts_at, true))->toEqual(7.0)
            ->and(StateTransition::query()->where(['subject_type' => 'travel_promotion', 'subject_id' => $promotion->id])->pluck('to_status')->all())->toBe(['pending_payment', 'active'])
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('100.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('5.00')
            ->and(ledgerBalance(AccountRef::gateway($payment->method)))->toBe('105.00');
        assertLedgerBalanced();

        // D11: nothing else is charged to the agency — no statement lines, no vendor postings.
        expect(VendorBalanceItem::query()->where('vendor_id', $agency->id)->exists())->toBeFalse()
            ->and(ledgerBalance(AccountRef::vendor($agency->id)))->toBe('0.00');

        // Live: first, labelled.
        expect(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$trip->slug.'*']);
        $this->actingAs($owner)->get(onPanel('travel-agency', 'promotions'))->assertOk()->assertSee($promotion->number)->assertSee('Receipt');
        $this->actingAs($owner)->get(onPanel('travel-agency', "promotions/{$promotion->id}/receipt"))->assertOk()->assertSee($payment->reference)->assertSee('105.00');

        // The paid window ends → the job expires it (per country) and the label goes.
        $this->travel(8)->days();
        $this->artisan('nawy:travel:expire-promotions')->assertSuccessful();
        expect($promotion->refresh()->status)->toBe(TravelPromotionStatus::Expired)
            ->and(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$trip->slug]);
    });

    it('also activates from the signed webhook-style verify on the payment API and queues a second window after the first', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Tbilisi');
        $first = promoPaid($agency, $trip, promoProduct('50.00', 7));
        $second = promoPaid($agency, $trip, promoProduct('80.00', 14));

        expect($second->status)->toBe(TravelPromotionStatus::Active)
            ->and($second->starts_at->equalTo($first->ends_at))->toBeTrue()
            ->and($second->ends_at->equalTo($first->ends_at->addDays(14)))->toBeTrue();

        $token = $owner->createToken('test')->plainTextToken;
        $reference = Payment::query()->where('payable_id', $second->id)->value('reference');
        $this->withToken($token)->getJson("/api/v1/uae-en/payments/{$reference}")->assertOk()
            ->assertJsonPath('data.purpose', 'travel_promotion')->assertJsonPath('data.subject.status', 'active');
    });

    it('keeps a failed payment pending for another try, then cancels the unpaid promotion', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Salalah');
        [$promotion, $payment] = app(TravelPromotions::class)->buy($agency, $trip, promoProduct(), $owner);
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
        app(PaymentAttempts::class)->refresh($payment);
        expect($promotion->refresh()->status)->toBe(TravelPromotionStatus::PendingPayment);

        $this->actingAs($owner)->post(onPanel('travel-agency', "promotions/{$promotion->id}/pay"))->assertRedirect();
        expect($promotion->payments()->count())->toBe(2);

        $this->travel(2)->hours();
        $this->artisan('nawy:travel:expire-promotions')->assertSuccessful();
        expect($promotion->refresh()->status)->toBe(TravelPromotionStatus::Cancelled)
            ->and($promotion->cancel_reason)->toBe('Payment not completed')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('0.00');
    });

    it('refuses an unpublished, inactive or someone else\'s trip and a product off sale', function () {
        [$owner, $agency] = promoAgency();
        [, $other] = promoAgency();
        $product = promoProduct();
        $service = app(TravelPromotions::class);

        expect(fn () => $service->buy($agency, promoTrip($agency, 'Draft', PackageStatus::Draft), $product, $owner))->toThrow(TransitionRefused::class)
            ->and(fn () => $service->buy($agency, promoTrip($agency, 'Sold out', PackageStatus::SoldOut), $product, $owner))->toThrow(TransitionRefused::class)
            ->and(fn () => $service->buy($agency, promoTrip($other, 'Theirs'), $product, $owner))->toThrow(TransitionRefused::class);

        $product->update(['is_active' => false]);
        expect(fn () => $service->buy($agency, promoTrip($agency, 'Live'), $product->refresh(), $owner))->toThrow(TransitionRefused::class)
            ->and(TravelPromotion::query()->count())->toBe(0);

        $agency->forceFill(['status' => 'suspended'])->save();
        expect(fn () => $service->buy($agency, promoTrip($agency, 'Suspended agency'), promoProduct(), $owner))->toThrow(TransitionRefused::class);
    });

    it('lets an admin refund a promotion: gateway refund, reverse posting, label gone; or cancel without refund', function () {
        [, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Muscat');
        $promotion = promoPaid($agency, $trip);
        $admin = adminAccount('uae', 'marketing');

        $this->actingAs($admin)->get(onPanel('admin', 'marketing/travel-promotions'))->assertOk()->assertSee($promotion->number);
        $this->actingAs($admin)->post(onPanel('admin', "marketing/travel-promotions/{$promotion->id}/refund"), ['reason' => 'Agency complaint'])->assertRedirect()->assertSessionHas('status');

        expect($promotion->refresh()->status)->toBe(TravelPromotionStatus::Refunded)
            ->and(Payment::query()->where('payable_id', $promotion->id)->value('status'))->toBe(PaymentStatus::Refunded)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('0.00')
            ->and(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$trip->slug]);
        assertLedgerBalanced();

        $other = promoPaid($agency, promoTrip($agency, 'Nizwa'));
        $this->actingAs($admin)->post(onPanel('admin', "marketing/travel-promotions/{$other->id}/cancel"), ['reason' => 'Misleading photos'])->assertRedirect();
        expect($other->refresh()->status)->toBe(TravelPromotionStatus::Cancelled)
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('100.00');
    });

    it('tells the agency on the panel return whether the promotion is active, queued, pending confirmation or not paid', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Muscat 3 nights');
        $returnFor = fn (Payment $payment) => onPanel('travel-agency', 'promotions/return').'?payment='.$payment->reference;

        // Gateway has not confirmed yet → pending payment confirmation.
        [$pending, $payment] = app(TravelPromotions::class)->buy($agency, $trip, promoProduct('60.00', 7), $owner);
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Pending);
        $this->actingAs($owner)->get($returnFor($payment))->assertRedirect(route('travel_agency.promotions'))
            ->assertSessionHas('status', 'Your promotion for Muscat 3 nights is pending payment confirmation. It goes live as soon as the gateway confirms the payment.');
        expect($pending->refresh()->status)->toBe(TravelPromotionStatus::PendingPayment);

        // Captured → active now.
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $this->actingAs($owner)->get($returnFor($payment))->assertRedirect(route('travel_agency.promotions'))
            ->assertSessionHas('status', fn (string $message) => str_starts_with($message, 'Payment received. Your promotion for Muscat 3 nights is now active until '));
        expect($pending->refresh()->status)->toBe(TravelPromotionStatus::Active);

        // A second paid window on the same trip waits for the first.
        [, $queuedPayment] = app(TravelPromotions::class)->buy($agency, $trip, promoProduct('90.00', 14), $owner);
        FakeGateway::willReport($queuedPayment->gateway_reference, PaymentStatus::Captured);
        $this->actingAs($owner)->get($returnFor($queuedPayment))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'is confirmed and starts on') && str_contains($message, 'after the one running now'));

        // Declined → not active, may try again.
        [$declined, $failedPayment] = app(TravelPromotions::class)->buy($agency, $trip, promoProduct('40.00', 3), $owner);
        FakeGateway::willReport($failedPayment->gateway_reference, PaymentStatus::Failed);
        $this->actingAs($owner)->get($returnFor($failedPayment))->assertSessionHasErrors(['action'])->assertSessionMissing('status');
        expect(session('errors')->first('action'))->toStartWith('The payment did not go through, so your promotion for Muscat 3 nights is not active.')
            ->and($declined->refresh()->status)->toBe(TravelPromotionStatus::PendingPayment);
    });

    it('says a payment captured after the promotion was cancelled is not live', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Dhofar');
        [$promotion, $payment] = app(TravelPromotions::class)->buy($agency, $trip, promoProduct(), $owner);
        $promotion->forceFill(['status' => TravelPromotionStatus::Cancelled])->save();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);

        $this->actingAs($owner)->get(onPanel('travel-agency', 'promotions/return').'?payment='.$payment->reference)->assertSessionHasErrors(['action']);
        expect(session('errors')->first('action'))->toBe('Payment received, but your promotion for Dhofar is not active (Cancelled). Contact Nawy support about a refund.');
    });
});

describe('ranking, cap, rotation and label', function () {
    it('puts live promoted trips first up to the slot cap, labelled, with the rest in the normal order and no duplicates', function () {
        [, $agency] = promoAgency();
        $organicNew = promoTrip($agency, 'Organic newest', approvedDaysAgo: 1);
        $organicOld = promoTrip($agency, 'Organic oldest', approvedDaysAgo: 9);
        $promoted = promoTrip($agency, 'Promoted', approvedDaysAgo: 5);
        promoPaid($agency, $promoted);

        $response = $this->getJson('/api/v1/uae-en/travel/packages')->assertOk()
            ->assertJsonPath('meta.sponsored', 1)->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.slug', $promoted->slug)->assertJsonPath('data.0.sponsored', true)->assertJsonPath('data.0.label', 'Sponsored')
            ->assertJsonPath('data.1.sponsored', false)->assertJsonPath('data.1.label', null);
        expect(collect($response->json('data'))->pluck('slug')->all())->toBe([$promoted->slug, $organicNew->slug, $organicOld->slug]);

        // Arabic label; page 2 shows no sponsored items.
        $this->getJson('/api/v1/uae-ar/travel/packages')->assertJsonPath('data.0.label', 'مُموَّل');
        $this->getJson('/api/v1/uae-en/travel/packages?page=2')->assertOk()->assertJsonPath('meta.sponsored', 0)->assertJsonCount(0, 'data');
        expect(TravelPromotion::query()->sole()->impressions)->toBe(2);
    });

    it('respects the search filters and never promotes an unpublished or inactive trip', function () {
        [, $agency] = promoAgency();
        $baku = promoTrip($agency, 'Baku');
        $paris = promoTrip($agency, 'Paris', attributes: ['destination_city' => 'Paris']);
        promoPaid($agency, $baku);
        promoPaid($agency, $paris);

        expect(promoSlugs('/api/v1/uae-en/travel/packages?destination=Paris'))->toBe([$paris->slug.'*']);

        // Sold out: still listed, never promoted; unpublished: gone.
        $paris->forceFill(['status' => PackageStatus::SoldOut])->save();
        expect(promoSlugs('/api/v1/uae-en/travel/packages?destination=Paris'))->toBe([$paris->slug]);
        $baku->forceFill(['status' => PackageStatus::Expired])->save();
        expect(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$paris->slug]);

        // Suspended agency: nothing of theirs.
        $paris->forceFill(['status' => PackageStatus::Active])->save();
        $agency->forceFill(['status' => 'suspended'])->save();
        expect(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([]);
    });

    it('rotates fairly when more promotions are live than the cap', function () {
        [, $agency] = promoAgency();
        $slot = app(TravelPromotions::class)->slot();
        $slot->forceFill(['max_concurrent' => 2])->save();
        $trips = collect(range(1, 3))->map(fn (int $n): TravelPackage => promoTrip($agency, "Trip {$n}"));
        $trips->each(fn (TravelPackage $trip) => promoPaid($agency, $trip));
        $this->travelTo(now()->addDay()->startOfDay()->addHour());

        $tops = [];
        $counts = array_fill_keys($trips->pluck('slug')->all(), 0);

        foreach (range(0, 2) as $window) {
            $data = $this->getJson('/api/v1/uae-en/travel/packages')->assertOk()->assertJsonPath('meta.sponsored', 2)->json('data');
            expect($data)->toHaveCount(3)
                ->and(collect($data)->take(2)->every(fn (array $card): bool => $card['sponsored']))->toBeTrue()
                ->and($data[2]['sponsored'])->toBeFalse();
            $tops[] = $data[0]['slug'];
            foreach (collect($data)->take(2) as $card) {
                $counts[$card['slug']]++;
            }
            $this->travel(15)->minutes();
        }

        // Each trip led once and was promoted in 2 of 3 windows.
        expect(collect($tops)->unique()->count())->toBe(3)
            ->and(array_values($counts))->toBe([2, 2, 2]);
    });

    it('labels the trip page and puts promoted similar trips first; the page-builder travel block uses the same order', function () {
        [, $agency] = promoAgency();
        $viewed = promoTrip($agency, 'Viewed');
        $similar = promoTrip($agency, 'Similar organic', approvedDaysAgo: 0);
        $promotedSimilar = promoTrip($agency, 'Similar promoted', approvedDaysAgo: 4);
        promoPaid($agency, $promotedSimilar);

        $this->getJson("/api/v1/uae-en/travel/packages/{$viewed->slug}")->assertOk()
            ->assertJsonPath('data.sponsored', false)->assertJsonPath('data.label', null)
            ->assertJsonPath('data.related.0.slug', $promotedSimilar->slug)->assertJsonPath('data.related.0.sponsored', true)
            ->assertJsonPath('data.related.1.slug', $similar->slug);
        $this->getJson("/api/v1/uae-en/travel/packages/{$promotedSimilar->slug}")->assertJsonPath('data.sponsored', true)->assertJsonPath('data.label', 'Sponsored');

        $blocks = app(BlockResolver::class)->resolve(['main' => [['id' => 'b1', 'type' => 'travel_packages_carousel', 'settings' => ['source' => 'latest', 'limit' => 2]]]]);
        expect(collect($blocks['main'][0]['data']['packages'])->map(fn (array $card): string => $card['slug'].($card['sponsored'] ? '*' : ''))->all())
            ->toBe([$promotedSimilar->slug.'*', $similar->slug]);
    });
});

describe('authorization and isolation', function () {
    it('lets owners and Operations buy, Finance and Viewer only look, and hides another agency\'s promotions', function () {
        [$owner, $agency] = promoAgency();
        $trip = promoTrip($agency, 'Owner trip');
        $product = promoProduct();
        $staff = fn (?string $role): User => tap(User::factory()->create(), fn (User $user) => $user->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => $this->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id, 'tenant_role' => $role]));

        $finance = $staff('finance');
        $this->actingAs($finance)->get(onPanel('travel-agency', 'promotions'))->assertOk();
        $this->actingAs($finance)->post(onPanel('travel-agency', 'promotions'), ['package_id' => $trip->id, 'product_id' => $product->id])->assertForbidden();
        $this->actingAs($staff(null))->post(onPanel('travel-agency', 'promotions'), ['package_id' => $trip->id, 'product_id' => $product->id])->assertForbidden();
        $this->actingAs($staff('operations'))->post(onPanel('travel-agency', 'promotions'), ['package_id' => $trip->id, 'product_id' => $product->id])->assertRedirect();
        expect(TravelPromotion::query()->count())->toBe(1);

        // Another agency cannot promote, see, pay or download this agency's promotion.
        [$intruder] = promoAgency();
        $promotion = TravelPromotion::query()->sole();
        $this->actingAs($intruder)->post(onPanel('travel-agency', 'promotions'), ['package_id' => $trip->id, 'product_id' => $product->id])->assertNotFound();
        $this->actingAs($intruder)->post(onPanel('travel-agency', "promotions/{$promotion->id}/pay"))->assertNotFound();
        $this->actingAs($intruder)->get(onPanel('travel-agency', "promotions/{$promotion->id}/receipt"))->assertNotFound();
        $this->actingAs($intruder)->get(onPanel('travel-agency', 'promotions'))->assertOk()->assertDontSee($promotion->number);
    });

    it('lists promotions in the travel agency ops app with a link to buy on the panel', function () {
        [$owner, $agency] = promoAgency();
        $promotion = promoPaid($agency, promoTrip($agency, 'App trip'));
        [, $other] = promoAgency();
        promoPaid($other, promoTrip($other, 'Not mine'));
        $token = $this->postJson('/api/v1/uae-en/ops/auth/login', ['email' => $owner->email, 'password' => 'password', 'device_name' => 'Pixel', 'role' => 'travel_agency'])->assertOk()->json('data.token');
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/uae-en/ops/travel/promotions')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $promotion->number)->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.amount.amount', '105.00')->assertJsonPath('data.0.product.duration_days', 7)
            ->assertJsonPath('meta.buy_url', route('travel_agency.promotions.create'));
    });

    it('lets Marketing set up options and Finance only view; Operations sees nothing', function () {
        app(TravelPromotions::class)->slot();
        $marketing = adminAccount('uae', 'marketing');
        $finance = adminAccount('uae', 'finance');
        $operations = adminAccount('uae', 'operations');

        $this->actingAs($marketing)->post(onPanel('admin', 'marketing/travel-promotions/products'), ['name_en' => '14 days', 'duration_days' => 14, 'price' => '199.50', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($marketing)->post(onPanel('admin', 'marketing/travel-promotions/slot'), ['max_concurrent' => 4, 'is_active' => 1])->assertRedirect();
        expect(TravelPromotionProduct::query()->sole())->price->toBe('199.5000')->duration_days->toBe(14)
            ->and(app(TravelPromotions::class)->slot()->max_concurrent)->toBe(4);

        $this->actingAs($finance)->get(onPanel('admin', 'marketing/travel-promotions'))->assertOk()->assertSee('14 days');
        $this->actingAs($finance)->post(onPanel('admin', 'marketing/travel-promotions/products'), ['name_en' => 'x', 'duration_days' => 3, 'price' => '1'])->assertForbidden();
        $this->actingAs($operations)->get(onPanel('admin', 'marketing/travel-promotions'))->assertForbidden();
    });

    it('keeps promotions, prices and rankings per country', function () {
        [, $agency] = promoAgency();
        $uaeTrip = promoTrip($agency, 'UAE trip');
        promoPaid($agency, $uaeTrip);

        $this->actAsCountry('omn');
        [, $omanAgency] = promoAgency('omn');
        $omanTrip = promoTrip($omanAgency, 'Oman trip');

        expect(TravelPromotion::query()->count())->toBe(0)
            ->and(TravelPromotionProduct::query()->count())->toBe(0)
            ->and(promoSlugs('/api/v1/omn-en/travel/packages'))->toBe([$omanTrip->slug]);

        $this->actAsCountry('uae');
        expect(promoSlugs('/api/v1/uae-en/travel/packages'))->toBe([$uaeTrip->slug.'*']);
    });

    it('never offers the travel placement as a banner slot', function () {
        $slot = app(TravelPromotions::class)->slot();
        $vendor = Vendor::factory()->create();

        expect(AdSlot::query()->bookable()->whereKey($slot->id)->exists())->toBeFalse()
            ->and(fn () => app(AdBookings::class)->draft($slot, AdvertiserBilling::VENDOR, $vendor->id, ['starts_on' => now()->addDays(2)->toDateString(), 'ends_on' => now()->addDays(3)->toDateString()], UploadedFile::fake()->image('a.jpg'), 1))
            ->toThrow(TransitionRefused::class);
    });
});

describe('money with 3-decimal currencies', function () {
    it('prices, books VAT and refunds an OMR promotion to the baisa', function () {
        $this->actAsCountry('omn');
        [, $agency] = promoAgency('omn');
        $promotion = promoPaid($agency, promoTrip($agency, 'Jebel Akhdar'), promoProduct('10.500', 7));

        expect($promotion->currency)->toBe('OMR')
            ->and((string) $promotion->amount)->toBe('10.5000')
            ->and((string) $promotion->vat_amount)->toBe('0.5000')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('10.000')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::VatPayable)))->toBe('0.500');
        assertLedgerBalanced();

        // A price that does not divide evenly: 2.345 incl. 5% VAT → VAT 0.112 (half-up), net 2.233.
        $odd = promoPaid($agency, promoTrip($agency, 'Wahiba'), promoProduct('2.345', 3));
        expect((string) $odd->vat_amount)->toBe('0.1120')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AdRevenue)))->toBe('12.233');
        assertLedgerBalanced();

        // Admin prices in OMR take 3 decimals, not 4.
        $admin = adminAccount('omn', 'marketing');
        $this->actingAs($admin)->post(onPanel('admin', 'marketing/travel-promotions/products'), ['name_en' => 'Bad', 'duration_days' => 3, 'price' => '2.3455', 'is_active' => 1])->assertSessionHasErrors('price');
        $this->actingAs($admin)->post(onPanel('admin', 'marketing/travel-promotions/products'), ['name_en' => 'Good', 'duration_days' => 3, 'price' => '2.250', 'is_active' => 1])->assertSessionHasNoErrors();
        expect(TravelPromotionProduct::query()->where('name_en', 'Good')->value('price'))->toBe('2.2500');
    });
});
