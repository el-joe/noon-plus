<?php
// Open Souq browser-test records (idempotent; test values only, never production).
// Run through `php artisan tinker --execute`; prints one JSON line prefixed "SOUQ_FIXTURES=".
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;

if (app()->environment('production')) { throw new RuntimeException('refuses production'); }
$country = Country::query()->where('code', 'uae')->sole();
$mkUser = function (string $email, string $name, bool $twoFactor) {
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->forceFill(['name' => $name, 'password' => 'E2e-Passw0rd!', 'two_factor_enabled' => $twoFactor, 'email_verified_at' => $user->email_verified_at ?? now(), 'status' => 'active'])->save();
    return $user;
};
$seller = $mkUser('e2e-souq-seller@nawy.test', 'E2E Souq Seller', true);
$broker = $mkUser('e2e-souq-broker@nawy.test', 'E2E Souq Broker', true);
$admin = User::query()->where('email', 'e2e-admin@nawy.test')->sole();
$customer = User::query()->where('email', 'e2e-customer@nawy.test')->sole();

$out = app(CountryContext::class)->runAs($country, function () use ($country, $seller, $broker, $admin) {
    $shop = Vendor::query()->firstOrCreate(['slug' => 'e2e-souq-shop'], [
        'owner_user_id' => $seller->id, 'type' => 'classified', 'store_name' => 'E2E Souq Shop', 'status' => Vendor::STATUS_ACTIVE,
        'contact_phone' => '+971500000098', 'verified_at' => now(), 'seller_agreement_accepted_at' => now(),
    ]);
    $seller->memberships()->firstOrCreate(
        ['role' => MembershipRole::ClassifiedSeller, 'country_id' => $country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $shop->id],
        ['status' => 'active'],
    );
    $gates = app(ContractGates::class);
    if (($version = $gates->sellerAgreement()) !== null && ! $gates->sellerAgreementAccepted($shop)) {
        app(ContractSigner::class)->sign($version, SignerType::Vendor, $shop->id, $seller->id, $seller->name, SignatureMethod::Click, '127.0.0.1', 'E2E souq');
    }

    $profile = MarketerProfile::query()->where('user_id', $broker->id)->first()
        ?? app(MarketerProfiles::class)->register($broker, ['broker'], ['whatsapp_phone' => '+971500000096', 'display_name' => 'E2E Souq Broker']);
    $profile->forceFill(['global_status' => MarketerStatus::Active])->save();

    // Categories of our own: one that needs contracts (seller + buyer), one with a 2-photo limit.
    $contractCat = ClassifiedCategory::query()->firstOrCreate(['slug' => 'e2e-contract-souq'], [
        'name_en' => 'E2E Contract goods', 'name_ar' => 'سلع بعقد للاختبار', 'vertical' => 'general', 'requires_contract' => true, 'max_photos' => 20, 'allows_video' => true, 'is_active' => true, 'sort_order' => 90,
    ]);
    $fewCat = ClassifiedCategory::query()->firstOrCreate(['slug' => 'e2e-few-photos-souq'], [
        'name_en' => 'E2E Two-photo goods', 'name_ar' => 'سلع بصورتين للاختبار', 'vertical' => 'general', 'requires_contract' => false, 'max_photos' => 2, 'allows_video' => false, 'is_active' => true, 'sort_order' => 91,
    ]);
    foreach ([[ContractKind::SellerCategory, 'E2E Souq seller contract'], [ContractKind::CustomerPurchase, 'E2E Souq buyer terms']] as [$kind, $name]) {
        $template = ContractTemplate::query()->firstOrCreate(['country_id' => $country->id, 'kind' => $kind, 'name' => $name]);
        if ($template->publishedVersion === null) {
            $publisher = app(ContractPublisher::class);
            $publisher->publish($publisher->draft($template, ['body_en' => "$name. The seller and buyer agree to these e2e test terms.", 'body_ar' => 'شروط الاختبار']), $admin->id);
        }
        ContractAssignment::query()->firstOrCreate(['contract_template_id' => $template->id, 'country_id' => $country->id, 'subject_type' => ContractAssignment::SUBJECT_CLASSIFIED_CATEGORY, 'subject_key' => (string) $contractCat->id]);
    }

    // Live ads the request / chat flows act on.
    $live = function (string $title, int $owner, array $data, ?Vendor $vendor = null, ?MarketerProfile $mk = null) use ($admin) {
        $ad = ClassifiedAd::query()->where('title', $title)->latest('id')->first();
        $machine = app(ClassifiedAdMachine::class);
        if ($ad === null) {
            $ad = app(ClassifiedAds::class)->create($owner, $data + ['title' => $title], $vendor ? ActorType::Vendor : ActorType::Customer, $vendor, $mk);
            app(ClassifiedAds::class)->addPhoto($ad, \Illuminate\Http\UploadedFile::fake()->image('e2e-souq.jpg', 800, 600));
            $ad = $machine->submit($ad, ActorType::Customer, $owner);
        }
        if ($ad->status !== ClassifiedAdStatus::Active) {
            $ad->forceFill(['status' => ClassifiedAdStatus::PendingReview])->save();
            $ad = $machine->approve($ad->refresh(), $admin->id);
        }
        return $ad->refresh();
    };
    $phones = ClassifiedCategory::query()->where('slug', 'electronics-souq')->sole();
    $flats = ClassifiedCategory::query()->where('slug', 'demo-apartments-rent')->sole();
    $shopAd = $live('E2E Souq shop — Galaxy S24 Ultra 512GB', $seller->id, [
        'category_id' => $phones->id, 'kind' => 'offer', 'description' => 'Boxed, one year warranty left. E2E test ad.', 'price' => '3200', 'city' => 'Dubai', 'area' => 'Deira',
        'latitude' => 25.2697, 'longitude' => 55.3095, 'contact_phone' => '+971500000098',
    ], $shop);
    $brokerAd = $live('E2E broker — 2-bed in Business Bay', $broker->id, [
        'category_id' => $flats->id, 'kind' => 'offer', 'description' => 'Bright 2-bed, canal view. E2E test ad.', 'price' => '120000', 'city' => 'Dubai', 'area' => 'Business Bay',
        'latitude' => 25.1857, 'longitude' => 55.2640, 'contact_phone' => '+971500000096',
        'property' => ['purpose' => 'rent', 'property_type' => 'apartment', 'bedrooms' => 2, 'bathrooms' => 2, 'area_sqm' => 110, 'furnishing' => 'unfurnished', 'rent_period' => 'yearly', 'permit_number' => 'RERA-E2E-1'],
    ], null, $profile);
    $contractAd = $live('E2E contract goods — vintage watch', $seller->id, [
        'category_id' => $contractCat->id, 'kind' => 'offer', 'description' => 'Needs buyer terms. E2E test ad.', 'price' => '900', 'city' => 'Dubai', 'contact_phone' => '+971500000098',
    ], $shop);
    if ($contractAd->contact_phone === null) { $contractAd->forceFill(['contact_phone' => '+971500000098'])->save(); }

    return [
        'seller' => ['email' => $seller->email, 'shop_slug' => $shop->slug, 'shop_id' => $shop->id],
        'broker' => ['email' => $broker->email, 'profile_id' => $profile->id, 'slug' => $profile->slug],
        'categories' => ['contract' => $contractCat->slug, 'contract_id' => $contractCat->id, 'few' => $fewCat->slug, 'few_id' => $fewCat->id],
        'ads' => ['shop' => $shopAd->number, 'broker' => $brokerAd->number, 'contract' => $contractAd->number],
    ];
});
echo 'SOUQ_FIXTURES='.json_encode($out).PHP_EOL;
