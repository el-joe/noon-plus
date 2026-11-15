<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Exceptions\ContractNotConfigured;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Models\Country;
use App\Domains\Listings\Support\ListingSubmissionGates;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
});

/**
 * A template with one published version (editor mode).
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedContract(ContractKind $kind, ?Country $country, array $attributes = [], string $body = 'Agreement for {store_name}'): ContractVersion
{
    $template = ContractTemplate::query()->create(['country_id' => $country?->id, 'kind' => $kind, 'name' => $kind->label().' '.($country?->code ?? 'global')] + $attributes);
    $version = app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية '.$body, 'body_en' => $body]);

    return app(ContractPublisher::class)->publish($version, 1);
}

function contractCountry(string $code): Country
{
    return Country::query()->where('code', $code)->sole();
}

describe('resolver (no cross-country fallback)', function () {
    it('prefers the country\'s own template over the legacy one', function () {
        $legacy = publishedContract(ContractKind::SellerPlatform, null);
        $own = publishedContract(ContractKind::SellerPlatform, $this->country);

        expect(app(ContractResolver::class)->current(ContractKind::SellerPlatform, $this->country)->id)->toBe($own->id)
            ->and(app(ContractResolver::class)->current(ContractKind::SellerPlatform, contractCountry('omn'))->id)->toBe($legacy->id);
    });

    it('never uses another country\'s template', function () {
        publishedContract(ContractKind::SellerPlatform, contractCountry('ksa'));
        publishedContract(ContractKind::MarketerOnboarding, contractCountry('ksa'));

        expect(app(ContractResolver::class)->current(ContractKind::SellerPlatform, $this->country))->toBeNull()
            ->and(app(ContractResolver::class)->current(ContractKind::MarketerOnboarding, $this->country))->toBeNull()
            ->and(app(ContractResolver::class)->current(ContractKind::SellerPlatform, contractCountry('ksa')))->not->toBeNull();
    });

    it('does not fall back to the legacy template when the country has its own but unpublished one', function () {
        publishedContract(ContractKind::SellerPlatform, null);
        ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Draft only']);

        expect(app(ContractResolver::class)->current(ContractKind::SellerPlatform, $this->country))->toBeNull();
    });

    it('resolves an individual contract: product, nearest category, then the country default', function () {
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();
        $product = Product::factory()->create(['category_id' => $child->id]);
        $default = publishedContract(ContractKind::SellerCategory, $this->country, ['is_default' => true]);
        $parentContract = publishedContract(ContractKind::SellerCategory, $this->country);
        ContractAssignment::query()->create(['contract_template_id' => $parentContract->contract_template_id, 'country_id' => $this->country->id, 'subject_type' => 'category', 'subject_key' => (string) $parent->id]);
        $productContract = publishedContract(ContractKind::SellerCategory, $this->country);
        ContractAssignment::query()->create(['contract_template_id' => $productContract->contract_template_id, 'country_id' => $this->country->id, 'subject_type' => 'product', 'subject_key' => (string) $product->id]);
        $resolver = app(ContractResolver::class);

        expect($resolver->forCategory(ContractKind::SellerCategory, $this->country, $child, $product)->version->id)->toBe($productContract->id)
            ->and($resolver->forCategory(ContractKind::SellerCategory, $this->country, $child)->version->id)->toBe($parentContract->id)
            ->and($resolver->forCategory(ContractKind::SellerCategory, $this->country, $child)->subjectId)->toBe($parent->id)
            ->and($resolver->forCategory(ContractKind::SellerCategory, $this->country, Category::factory()->create())->version->id)->toBe($default->id);

        $product->forceFill(['contract_requirement' => 'exempt'])->save();
        expect($resolver->forCategory(ContractKind::SellerCategory, $this->country, $child, $product)->version->id)->toBe($parentContract->id);
    });

    it('raises ContractNotConfigured for a category without any contract, even when another country has one', function () {
        $category = Category::factory()->create();
        $ksa = publishedContract(ContractKind::SellerCategory, contractCountry('ksa'), ['is_default' => true]);
        ContractAssignment::query()->create(['contract_template_id' => $ksa->contract_template_id, 'country_id' => $this->country->id, 'subject_type' => 'category', 'subject_key' => (string) $category->id]);

        expect(fn () => app(ContractResolver::class)->forCategory(ContractKind::SellerCategory, $this->country, $category))->toThrow(ContractNotConfigured::class)
            ->and(app(ContractResolver::class)->forCategory(ContractKind::CustomerPurchase, $this->country, $category))->toBeNull();
    });

    it('filters collaboration contracts by marketer job type and lists uncovered types', function () {
        publishedContract(ContractKind::SellerMarketer, $this->country, ['destinations' => ['influencer']]);

        expect(app(ContractResolver::class)->collaboration($this->country, ['influencer']))->toHaveCount(1)
            ->and(app(ContractResolver::class)->collaboration($this->country, ['affiliate']))->toHaveCount(0)
            ->and(app(ContractResolver::class)->uncoveredMarketerDestinations($this->country))->toBe(['affiliate', 'broker']);
    });
});

describe('signatures and evidence', function () {
    it('stores the frozen copy on the private disk with its SHA-256, IP, user agent and method', function () {
        $version = publishedContract(ContractKind::SellerPlatform, $this->country);
        $vendor = Vendor::factory()->create(['store_name' => 'Pulse Audio']);

        $signature = app(ContractSigner::class)->sign($version, SignerType::Vendor, $vendor->id, 7, 'Noura Saleh', SignatureMethod::Click, '203.0.113.9', 'Mozilla/5.0 Test', variables: ['store_name' => 'Pulse Audio']);
        $bytes = Storage::disk('local')->get($signature->frozen_path);

        expect($signature)
            ->sha256->toBe(hash('sha256', $bytes))
            ->ip_address->toBe('203.0.113.9')
            ->user_agent->toBe('Mozilla/5.0 Test')
            ->method->toBe(SignatureMethod::Click)
            ->full_name->toBe('Noura Saleh')
            ->version->toBe(1)
            ->and($bytes)->toContain('Agreement for Pulse Audio')->toContain('Noura Saleh')
            ->and(str_starts_with($signature->frozen_path, 'contracts/signatures/uae/seller_platform/'))->toBeTrue()
            ->and(app(ContractSigner::class)->verify($signature))->toBeTrue();

        Storage::disk('local')->put($signature->frozen_path, $bytes.'tampered');
        expect(app(ContractSigner::class)->verify($signature))->toBeFalse();
    });

    it('freezes the uploaded file itself in file mode', function () {
        Storage::disk('local')->put('contracts/templates/x/agreement.pdf', '%PDF-1.4 binary');
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'File agreement', 'mode' => 'file']);
        $version = $template->versions()->create(['version' => 1, 'status' => ContractVersionStatus::Draft, 'file_path_ar' => 'contracts/templates/x/agreement.pdf']);
        app(ContractPublisher::class)->publish($version);

        $signature = app(ContractSigner::class)->sign($version->refresh(), SignerType::Vendor, 1, 1, 'A Seller');

        expect($signature->sha256)->toBe(hash('sha256', '%PDF-1.4 binary'))->and($signature->frozen_path)->toEndWith('.pdf');
    });

    it('refuses to sign a draft or without a name, and does not duplicate a current signature', function () {
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Draft']);
        $draft = app(ContractPublisher::class)->draft($template, ['body_ar' => 'نص']);

        expect(fn () => app(ContractSigner::class)->sign($draft, SignerType::Vendor, 1, 1, 'Name'))->toThrow(TransitionRefused::class);

        $version = publishedContract(ContractKind::SellerPlatform, contractCountry('omn'));
        expect(fn () => app(ContractSigner::class)->sign($version, SignerType::Vendor, 1, 1, '  '))->toThrow(TransitionRefused::class);

        $first = app(ContractSigner::class)->sign($version, SignerType::Vendor, 1, 1, 'Name');
        expect(app(ContractSigner::class)->sign($version, SignerType::Vendor, 1, 1, 'Name')->id)->toBe($first->id);
    });
});

describe('template lifecycle', function () {
    it('needs the Arabic text before publishing', function () {
        $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'EN only']);
        $draft = app(ContractPublisher::class)->draft($template, ['body_en' => 'English']);

        expect(fn () => app(ContractPublisher::class)->publish($draft))->toThrow(TransitionRefused::class, 'Arabic');
    });

    it('publishing a new click-wrap version retires the old one and makes signers re-sign', function () {
        $v1 = publishedContract(ContractKind::SellerPlatform, $this->country);
        $vendor = Vendor::factory()->create();
        app(ContractSigner::class)->sign($v1, SignerType::Vendor, $vendor->id, 1, 'Owner');
        expect($vendor->hasAcceptedSellerAgreement())->toBeTrue();

        $v2 = app(ContractPublisher::class)->draft($v1->template, ['body_ar' => 'نسخة ٢']);
        app(ContractPublisher::class)->publish($v2);

        expect($v1->refresh()->status)->toBe(ContractVersionStatus::Retired)
            ->and($v2->refresh()->version)->toBe(2)
            ->and(ContractSignature::query()->sole()->status)->toBe(ContractSignature::STATUS_SUPERSEDED)
            ->and($vendor->hasAcceptedSellerAgreement())->toBeFalse()
            ->and(fn () => app(ContractPublisher::class)->publish($v1->refresh()))->toThrow(TransitionRefused::class);
    });

    it('schedules a notice version and records continued use as consent when it takes effect', function () {
        $v1 = publishedContract(ContractKind::SellerPlatform, $this->country, ['consent_mode' => 'notice']);
        $vendor = Vendor::factory()->create();
        app(ContractSigner::class)->sign($v1, SignerType::Vendor, $vendor->id, 1, 'Owner Name');
        $v2 = app(ContractPublisher::class)->draft($v1->template, ['body_ar' => 'تحديث', 'change_summary' => 'Fees']);

        app(ContractPublisher::class)->publish($v2, 1, now()->addDays(14));
        expect($v2->refresh()->status)->toBe(ContractVersionStatus::Scheduled)
            ->and($vendor->hasAcceptedSellerAgreement())->toBeTrue();

        $this->travel(15)->days();
        $this->artisan('nawy:contracts:apply-effective')->assertSuccessful();

        $current = ContractSignature::query()->where('status', 'active')->sole();
        expect($v2->refresh()->status)->toBe(ContractVersionStatus::Published)
            ->and($current->method)->toBe(SignatureMethod::ContinuedUse)
            ->and($current->contract_version_id)->toBe($v2->id)
            ->and($current->full_name)->toBe('Owner Name')
            ->and($vendor->hasAcceptedSellerAgreement())->toBeTrue();
    });
});

describe('gates', function () {
    it('keeps the registration stamp where no Seller Agreement is published, and needs a signature where one is', function () {
        $vendor = Vendor::factory()->create(['seller_agreement_accepted_at' => now()]);
        expect($vendor->hasAcceptedSellerAgreement())->toBeTrue();

        publishedContract(ContractKind::SellerPlatform, $this->country);
        expect($vendor->hasAcceptedSellerAgreement())->toBeFalse();
    });

    it('wires the listing contract gate to the engine', function () {
        $category = Category::factory()->create(['requires_contract' => true]);
        $vendor = Vendor::factory()->create(['seller_agreement_accepted_at' => now()]);
        $gates = app(ListingSubmissionGates::class);

        expect($gates->checklist($vendor, $category)['category_contract']['passed'])->toBeFalse();

        $version = publishedContract(ContractKind::SellerCategory, $this->country);
        ContractAssignment::query()->create(['contract_template_id' => $version->contract_template_id, 'country_id' => $this->country->id, 'subject_type' => 'category', 'subject_key' => (string) $category->id]);
        expect($gates->hasSignedCategoryContract($vendor, $category))->toBeFalse();

        app(ContractSigner::class)->sign($version, SignerType::Vendor, $vendor->id, 1, 'Owner', subjectType: 'category', subjectId: $category->id);
        expect($gates->hasSignedCategoryContract($vendor, $category))->toBeTrue()
            ->and(fn () => $gates->assertPasses($vendor, $category))->not->toThrow(Exception::class);
    });
});
