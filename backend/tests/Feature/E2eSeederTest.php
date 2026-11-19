<?php

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    Storage::fake('local');
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'fee_percent' => '10']);
});

it('refuses to seed e2e test records in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(E2eSeeder::class)->run(app(CountryContext::class)))->toThrow(RuntimeException::class, 'refuses to run in production');
    expect(User::query()->where('email', 'like', 'e2e-%')->exists())->toBeFalse();
});

it('creates the panel accounts with two-step sign-in and tops up the records each panel acts on', function () {
    sellableListing();
    sellableListing();

    $this->seed(E2eSeeder::class);
    $this->seed(E2eSeeder::class);

    expect(User::query()->where('email', 'like', 'e2e-%@nawy.test')->count())->toBe(8)
        ->and(User::query()->where('email', 'e2e-admin@nawy.test')->sole()->two_factor_enabled)->toBeTrue()
        ->and(User::query()->where('email', 'e2e-customer@nawy.test')->sole()->two_factor_enabled)->toBeFalse();

    foreach ([MembershipRole::Admin, MembershipRole::ProductVendor, MembershipRole::TravelAgency, MembershipRole::CarrierSupervisor, MembershipRole::DeliveryAgent, MembershipRole::Affiliate] as $role) {
        expect(User::query()->where('email', 'like', 'e2e-%')->whereHas('memberships', fn ($q) => $q->where('role', $role))->count())->toBe(1);
    }

    $fixtures = json_decode((string) Storage::disk('local')->get('e2e-fixtures.json'), true);
    expect(Listing::query()->findOrFail($fixtures['pending_listing_id'])->status)->toBe(ListingStatus::PendingReview)
        ->and(SubOrder::query()->where('number', 'like', 'NW-AE-E2E%')->count())->toBe(5)
        ->and($fixtures['referral_code'])->not->toBeNull()
        ->and($fixtures['legacy_redirect'])->toBe(['from' => '/uae-en/live-streams', 'to' => '/uae-en/live'])
        ->and(DB::table('legacy_redirects')->where('from_path', '/uae-en/live-streams')->pluck('to_path')->all())->toBe(['/uae-en/live']);
});

it('signs the Seller Agreement in force for the e2e stores so the panels are not gated', function () {
    sellableListing();
    sellableListing();
    $template = ContractTemplate::query()->create(['country_id' => null, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller Agreement']);
    $version = app(ContractPublisher::class)->publish(app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية', 'body_en' => 'Agreement']), 1);

    $this->seed(E2eSeeder::class);
    $this->seed(E2eSeeder::class);

    foreach (['e2e-store', 'e2e-travel-agency'] as $slug) {
        $store = Vendor::query()->where('slug', $slug)->sole();
        expect(app(ContractGates::class)->sellerAgreementAccepted($store))->toBeTrue()
            ->and(ContractSignature::query()->where('contract_version_id', $version->id)->where('signer_id', $store->id)->count())->toBe(1);
    }
});
