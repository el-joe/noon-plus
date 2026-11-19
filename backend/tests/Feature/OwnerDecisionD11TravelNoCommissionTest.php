<?php

use App\Domains\Advertising\Models\AdSlot;
use App\Domains\Advertising\Support\AdBookings;
use App\Domains\Advertising\Support\AdvertiserBilling;
use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Support\CampaignRefused;
use App\Domains\Campaigns\Support\CampaignWizard;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
| Owner decision D11 (2026-11-18): travel agencies pay no commission and no
| marketer-campaign fees on Nawy. They can still buy paid ad packages.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Storage::fake('public');
    Storage::fake('local');
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
    $this->agency = Vendor::factory()->create(['type' => 'travel']);
});

function campaignRefusalCode(Closure $attempt): ?string
{
    try {
        $attempt();
    } catch (CampaignRefused $refused) {
        return $refused->errorCode;
    }

    return null;
}

it('refuses every marketer campaign for a travel agency or on its listing', function () {
    $listing = sellableListing('105.00', 50, vendor: $this->agency);
    [, $marketer] = marketerAccount(['affiliate']);
    $wizard = app(CampaignWizard::class);

    expect(campaignRefusalCode(fn () => $wizard->create(CampaignOrigin::TravelAgency, ['listing_id' => $listing->id, 'title' => 'Trip'], $this->agency, 1)))->toBe('travel_no_campaigns')
        ->and(campaignRefusalCode(fn () => $wizard->create(CampaignOrigin::TravelAgency, ['listing_id' => $listing->id, 'title' => 'Trip'], null, null)))->toBe('travel_no_campaigns')
        ->and(campaignRefusalCode(fn () => $wizard->create(CampaignOrigin::Vendor, ['listing_id' => $listing->id, 'title' => 'Trip', 'open_to_affiliates' => true, 'ends_at' => now()->addMonth()->toDateString()], $this->agency, 1)))->toBe('travel_no_campaigns')
        ->and(campaignRefusalCode(fn () => $wizard->create(CampaignOrigin::Platform, ['listing_id' => $listing->id, 'title' => 'Trip', 'open_to_affiliates' => true, 'ends_at' => now()->addMonth()->toDateString()], null, 1)))->toBe('travel_no_campaigns')
        ->and(campaignRefusalCode(fn () => $wizard->requestByMarketer($marketer, $listing, 'Promote my trip')))->toBe('travel_no_campaigns')
        ->and(MarketerCampaign::query()->count())->toBe(0)
        ->and(VendorBalanceItem::query()->where('vendor_id', $this->agency->id)->whereIn('type', [VendorBalanceItem::TYPE_CAMPAIGN_FEE, VendorBalanceItem::TYPE_MARKETER_COMMISSION, VendorBalanceItem::TYPE_SAMPLE_COMMISSION])->exists())->toBeFalse();

    // A regular store still runs campaigns.
    expect(liveCampaign(Vendor::factory()->create(), live: false)->exists)->toBeTrue();
});

it('settles a travel agency with no commission, no VAT on commission and no fees', function () {
    app(SettingsService::class)->set('payout.fixed_fee_per_sub_order', '2.00', $this->country);
    [$order] = placeOrderVia('card', '100.00', 'uae-en', 2, vendor: $this->agency);
    captureOrderPayment($order);
    $subOrder = $order->subOrders()->first();
    $subOrder->items()->update(['commission_rate' => '12.50']);
    moveSubOrderTo($subOrder, SubOrderStatus::Completed);

    expect(SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->sole())
        ->gross->toBe('200.0000')
        ->commission->toBe('0.0000')
        ->commission_vat->toBe('0.0000')
        ->fees->toBe('0.0000')
        ->fbn_fees->toBe('0.0000')
        ->net->toBe('200.0000');
});

it('still sells paid ad packages to a travel agency', function () {
    $slot = AdSlot::query()->create(['code' => 'travel-hero', 'name' => 'Travel hero', 'placement' => 'homepage', 'price_per_day' => '100.00', 'max_concurrent' => 1]);
    $bookings = app(AdBookings::class);

    $booking = $bookings->draft($slot, AdvertiserBilling::VENDOR, $this->agency->id, ['starts_on' => now()->addDays(2)->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(), 'link_url' => 'https://nawy.test/trips'], UploadedFile::fake()->image('trip.jpg'), 1);
    $bookings->submit($booking, 1);

    expect((string) $booking->refresh()->amount)->toBe('200.0000')
        ->and(ledgerBalance(AccountRef::vendor($this->agency->id)))->toBe('-200.00')
        ->and(VendorBalanceItem::query()->where(['vendor_id' => $this->agency->id, 'type' => VendorBalanceItem::TYPE_AD_BOOKING])->exists())->toBeTrue();
});

it('shows no commission figures for a travel agency in admin', function () {
    $admin = adminAccount();
    $store = Vendor::factory()->create();

    $this->actingAs($admin)->get(onPanel('admin', "vendors/{$this->agency->id}"))->assertOk()
        ->assertSee('data-travel-no-commission', false)->assertDontSee('Gross sales settled');
    $this->actingAs($admin)->get(onPanel('admin', "vendors/{$store->id}"))->assertOk()
        ->assertSee('Gross sales settled')->assertDontSee('data-travel-no-commission', false);
});
