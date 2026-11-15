<?php

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Notifications\ApplicationEmailCodeNotification;
use App\Domains\Onboarding\Notifications\PartnerWelcomeNotification;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\RequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function applicationsUrl(string $path = '', string $market = 'uae-en'): string
{
    return "/api/v1/{$market}/partner-applications".($path === '' ? '' : '/'.ltrim($path, '/'));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function applicantAccount(string $type, array $overrides = []): array
{
    return array_merge(['type' => $type, 'name' => 'Sara Al Mansoori', 'email' => 'sara@example.com', 'phone' => '+971501234567', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'], $overrides);
}

function requiredDocument(string $businessType, string $code = 'trade_licence', ?Country $country = null): DocumentType
{
    $country ??= app(CountryContext::class)->current();

    return DocumentType::query()->create(['country_id' => $country->id, 'code' => $code, 'name_en' => ucfirst(str_replace('_', ' ', $code)), 'name_ar' => 'وثيقة', 'business_type' => $businessType, 'requirement' => 'mandatory']);
}

function publishedAgreement(ContractKind $kind, Country $country): ContractVersion
{
    $template = ContractTemplate::query()->create(['country_id' => $country->id, 'kind' => $kind, 'name' => $kind->label()]);
    $version = app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية {store_name}', 'body_en' => 'Agreement for {store_name}']);

    return app(ContractPublisher::class)->publish($version, 1);
}

/**
 * Opens an application through the API and returns [number, token].
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: string, 1: string}
 */
function openApplication(string $type, array $overrides = [], string $market = 'uae-en'): array
{
    $response = test()->postJson(applicationsUrl('', $market), applicantAccount($type, $overrides))->assertCreated();
    [$number, $token] = [$response->json('data.number'), $response->json('meta.access_token')];
    $email = mb_strtolower(applicantAccount($type, $overrides)['email']);
    $code = null;
    Notification::assertSentOnDemand(ApplicationEmailCodeNotification::class, function (ApplicationEmailCodeNotification $mail, array $channels, AnonymousNotifiable $notifiable) use ($email, &$code): bool {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $code = $mail->code;
        }

        return true;
    });
    test()->postJson(applicationsUrl("{$number}/email/verify", $market), ['code' => $code], ['X-Application-Token' => $token])->assertOk();

    return [$number, $token];
}

/**
 * A complete vendor application, submitted (trade licence uploaded).
 *
 * @return array{0: PartnerApplication, 1: string}
 */
function submittedVendorApplication(array $account = []): array
{
    $type = DocumentType::query()->where('code', 'trade_licence')->where('business_type', 'vendor')->first() ?? requiredDocument('vendor');
    [$number, $token] = openApplication('vendor', $account);
    $headers = ['X-Application-Token' => $token];
    test()->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'SoundMax', 'slug' => 'soundmax-'.Str::lower(Str::random(4)), 'business_type' => 'llc', 'legal_name' => 'SoundMax Trading LLC', 'cr_number' => 'DED-1187742', 'fulfilment' => 'both'], $headers)->assertOk();
    test()->postJson(applicationsUrl("{$number}/steps/address"), ['city' => 'Dubai', 'street' => 'Bay Square', 'building' => 'Office 1204'], $headers)->assertOk();
    test()->post(applicationsUrl("{$number}/documents"), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertCreated();
    test()->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara Al Mansoori'], $headers)->assertOk();

    return [PartnerApplication::query()->where('number', $number)->sole(), $token];
}

describe('join options and advertise requests', function () {
    it('lists the join sections per country and closes a disabled section', function () {
        $admin = adminAccount('uae', 'vendor_relations');

        $this->getJson('/api/v1/uae-en/join-options')->assertOk()
            ->assertJsonPath('data.0.section', 'vendor')->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.3.types.1.type', 'broker');

        $this->actingAs($admin)->get(onPanel('admin', 'join-options'))->assertOk()->assertSee('Open Souq');
        $this->actingAs($admin)->put(onPanel('admin', 'join-options'), ['enabled' => ['vendor', 'open_souq', 'marketer', 'shipping']])->assertRedirect();

        $this->getJson('/api/v1/uae-en/join-options')->assertJsonPath('data.2.section', 'travel_agency')->assertJsonPath('data.2.enabled', false);
        $this->getJson('/api/v1/omn-en/join-options')->assertJsonPath('data.2.enabled', true);
        $this->postJson(applicationsUrl(), applicantAccount('travel_agency'))->assertStatus(422)->assertJsonPath('error.code', 'not_allowed');
        $this->postJson(applicationsUrl('', 'omn-en'), applicantAccount('travel_agency', ['phone' => '+96891234567']))->assertCreated();

        $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'join-options'))->assertForbidden();
    });

    it('saves an advertise request, confirms it and lets the ads team work the queue', function () {
        $this->postJson('/api/v1/uae-en/advertise-requests', ['name' => 'Sara', 'email' => 'sara@bloom.ae', 'phone' => '0501234567', 'advertiser_type' => 'seller', 'interest' => 'product_ads', 'monthly_budget' => 'AED 5,000 – 20,000'])
            ->assertStatus(422)->assertJsonValidationErrors('consent', 'error.fields');
        $this->postJson('/api/v1/uae-en/advertise-requests', ['name' => 'Sara', 'email' => 'sara@bloom.ae', 'phone' => '12', 'advertiser_type' => 'seller', 'interest' => 'product_ads', 'consent' => true])
            ->assertStatus(422)->assertJsonValidationErrors('phone', 'error.fields');

        $number = $this->postJson('/api/v1/uae-en/advertise-requests', ['name' => 'Sara', 'email' => 'sara@bloom.ae', 'phone' => '0501234567', 'company' => 'Bloom Home', 'advertiser_type' => 'seller', 'interest' => 'product_ads', 'consent' => true])
            ->assertCreated()->json('data.number');
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail, array $channels, AnonymousNotifiable $notifiable): bool => $mail->kind === 'advertise_inquiry.received' && $notifiable->routes['mail'] === 'sara@bloom.ae');

        $marketing = adminAccount('uae', 'marketing');
        $inquiry = AdvertiseInquiry::query()->sole();
        expect($inquiry->phone)->toBe('+971501234567');
        $this->actingAs($marketing)->get(onPanel('admin', 'advertise-requests'))->assertOk()->assertSee($number)->assertSee('Bloom Home');
        $this->actingAs($marketing)->post(onPanel('admin', "advertise-requests/{$inquiry->id}"), ['status' => 'converted'])->assertSessionHasErrors('action');
        $this->actingAs($marketing)->post(onPanel('admin', "advertise-requests/{$inquiry->id}"), ['status' => 'contacted', 'note' => 'Called, sending plan'])->assertRedirect();
        expect($inquiry->refresh()->status->value)->toBe('contacted')->and($inquiry->admin_note)->toBe('Called, sending plan')->and($inquiry->transitions()->count())->toBe(2);

        $this->actingAs(adminAccount('uae', 'content'))->post(onPanel('admin', "advertise-requests/{$inquiry->id}"), ['status' => 'closed'])->assertForbidden();
        $this->actingAs(adminAccount('omn', 'marketing'))->get(onPanel('admin', 'advertise-requests?country=omn'))->assertOk()->assertDontSee($number);
    });
});

describe('vendor wizard (5 steps)', function () {
    it('validates each step, saves drafts and resumes with the token only', function () {
        requiredDocument('vendor');
        $this->postJson(applicationsUrl(), applicantAccount('vendor', ['password_confirmation' => 'other']))->assertStatus(422)->assertJsonValidationErrors('password', 'error.fields');
        $this->postJson(applicationsUrl(), applicantAccount('vendor', ['phone' => '+96891234567']))->assertStatus(422)->assertJsonValidationErrors('phone', 'error.fields');

        [$number, $token] = openApplication('vendor');
        $headers = ['X-Application-Token' => $token];
        $application = PartnerApplication::query()->sole();
        expect($application->status)->toBe(ApplicationStatus::Draft)->and($application->token_hash)->not->toBe($token)
            ->and(Hash::check('secret-pass-1', $application->password_hash))->toBeTrue();

        $this->getJson(applicationsUrl($number))->assertNotFound();
        $this->getJson(applicationsUrl($number), ['X-Application-Token' => 'wrong'])->assertNotFound();
        $this->getJson(applicationsUrl($number), $headers)->assertOk()
            ->assertJsonPath('data.next_step', 'store')->assertJsonPath('data.steps.0.completed', true)
            ->assertJsonPath('data.documents.0.code', 'trade_licence')->assertJsonPath('data.documents.0.missing', true)
            ->assertJsonMissingPath('data.password_hash');

        $this->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'SoundMax', 'slug' => 'Bad Slug', 'business_type' => 'llc'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['slug', 'legal_name', 'cr_number', 'fulfilment'], 'error.fields');
        $this->postJson(applicationsUrl("{$number}/steps/agency"), ['agency_name' => 'x'], $headers)->assertStatus(422);
        Vendor::factory()->create(['slug' => 'taken-store']);
        $this->getJson(applicationsUrl('check-slug?slug=taken-store'))->assertJsonPath('data.available', false);
        $this->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'SoundMax', 'slug' => 'taken-store', 'business_type' => 'llc', 'legal_name' => 'SoundMax LLC', 'cr_number' => 'CR-1', 'fulfilment' => 'fbn'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('slug', 'error.fields');
        $this->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'SoundMax', 'slug' => 'soundmax', 'business_type' => 'llc', 'legal_name' => 'SoundMax LLC', 'cr_number' => 'CR-1', 'fulfilment' => 'fbn'], $headers)
            ->assertOk()->assertJsonPath('data.next_step', 'address')->assertJsonPath('data.data.store.store_name', 'SoundMax');
        $this->getJson(applicationsUrl('check-slug?slug=soundmax'))->assertJsonPath('data.available', false);

        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertStatus(422)->assertJsonPath('error.code', 'not_allowed');
        $this->postJson(applicationsUrl("{$number}/steps/address"), ['city' => 'Dubai', 'street' => 'Bay Square'], $headers)->assertOk()->assertJsonPath('data.next_step', 'documents');
        $this->postJson(applicationsUrl("{$number}/steps/documents"), [], $headers)->assertStatus(422)->assertJsonPath('error.code', 'documents_missing');
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertStatus(422);

        $type = DocumentType::query()->sole();
        $this->post(applicationsUrl("{$number}/documents"), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('virus.exe', 10)], $headers + ['Accept' => 'application/json'])->assertStatus(422);
        $documentId = $this->post(applicationsUrl("{$number}/documents"), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')], $headers + ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        Storage::disk('local')->assertExists(Document::query()->findOrFail($documentId)->file_path);
        $this->getJson(applicationsUrl($number), $headers)->assertJsonPath('data.next_step', 'agreement')->assertJsonPath('data.documents.0.missing', false);

        $this->deleteJson(applicationsUrl("{$number}/documents/{$documentId}"), [], $headers)->assertOk()->assertJsonPath('data.next_step', 'documents');
        expect(Document::query()->count())->toBe(0);
    });

    it('signs the published Seller Agreement at submission and refuses later edits', function () {
        $agreement = publishedAgreement(ContractKind::SellerPlatform, $this->country);
        [$application, $token] = submittedVendorApplication();

        expect($application->status)->toBe(ApplicationStatus::Submitted)->and($application->submitted_at)->not->toBeNull();
        $signature = ContractSignature::query()->sole();
        expect($signature->signer_type)->toBe(SignerType::Applicant)->and($signature->signer_id)->toBe($application->id)
            ->and($signature->contract_version_id)->toBe($agreement->id)->and($signature->method)->toBe(SignatureMethod::Registration);
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'partner_application.submitted');

        $this->postJson(applicationsUrl("{$application->number}/steps/address"), ['city' => 'Sharjah', 'street' => 'X'], ['X-Application-Token' => $token])->assertStatus(422);
        $this->getJson(applicationsUrl($application->number), ['X-Application-Token' => $token])->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.editable', false);
    });

    it('refuses a second open application or an existing seller for the same email', function () {
        openApplication('vendor');
        $this->postJson(applicationsUrl(), applicantAccount('vendor'))->assertStatus(422)->assertJsonValidationErrors('email', 'error.fields');
        $this->postJson(applicationsUrl(), applicantAccount('classified'))->assertCreated();

        [$user] = sellerAccount('uae');
        $this->postJson(applicationsUrl(), applicantAccount('vendor', ['email' => $user->email]))->assertStatus(422)->assertJsonValidationErrors('email', 'error.fields');
        $this->postJson(applicationsUrl('', 'omn-en'), applicantAccount('vendor', ['email' => $user->email, 'phone' => '+96891234567']))->assertCreated();
    });

    it('keeps applications inside their country', function () {
        [$number, $token] = openApplication('vendor');

        $this->getJson(applicationsUrl($number, 'omn-en'), ['X-Application-Token' => $token])->assertNotFound();
        $this->actAsCountry('omn');
        expect(PartnerApplication::query()->count())->toBe(0);
        $this->actAsCountry('uae');
        expect(PartnerApplication::query()->count())->toBe(1);

        $id = PartnerApplication::query()->value('id');
        $admin = adminAccount('omn', 'vendor_relations');
        $this->actingAs($admin)->get(onPanel('admin', 'applications?country=omn&status=draft'))->assertOk()->assertDontSee($number);
        $this->actingAs($admin)->get(onPanel('admin', "applications/{$id}?country=uae"))->assertNotFound();
        $this->actingAs(adminAccount('uae', 'vendor_relations'))->get(onPanel('admin', 'applications?status=draft'))->assertOk()->assertSee($number);
    });

    it('mails a fresh resume link without telling whether an application exists', function () {
        [$number, $token] = openApplication('vendor');
        Notification::fake();

        $this->postJson(applicationsUrl('resume-link'), ['email' => 'nobody@example.com', 'type' => 'vendor'])->assertStatus(202);
        Notification::assertNothingSent();
        $this->postJson(applicationsUrl('resume-link'), ['email' => 'sara@example.com', 'type' => 'vendor'])->assertStatus(202);
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'partner_application.resume' && str_contains($mail->lines[0], $number));
        $this->getJson(applicationsUrl($number), ['X-Application-Token' => $token])->assertNotFound();
    });
});

describe('other wizards', function () {
    it('runs the 2-step travel agency wizard with a logo, documents and the agency agreement', function () {
        $licence = requiredDocument('agency', 'tourism_licence');
        publishedAgreement(ContractKind::TravelAgency, $this->country);
        [$number, $token] = openApplication('travel_agency');
        $headers = ['X-Application-Token' => $token, 'Accept' => 'application/json'];

        $this->post(applicationsUrl("{$number}/steps/agency"), ['agency_name' => 'Nile Tours', 'licence_number' => 'TL-1'], $headers)->assertStatus(422)->assertJsonValidationErrors('logo', 'error.fields');
        $this->post(applicationsUrl("{$number}/steps/agency"), ['agency_name' => 'Nile Tours', 'licence_number' => 'TL-1', 'logo' => UploadedFile::fake()->image('logo.png')], $headers)
            ->assertOk()->assertJsonPath('data.next_step', null);
        expect(PartnerApplication::query()->sole()->logo_path)->not->toBeNull();

        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertStatus(422);
        $this->post(applicationsUrl("{$number}/documents"), ['document_type_id' => $licence->id, 'file' => UploadedFile::fake()->create('l.pdf', 50, 'application/pdf')], $headers)->assertCreated();
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => false, 'full_name' => 'Sara'], $headers)->assertStatus(422)->assertJsonValidationErrors('accept', 'error.fields');
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertOk()->assertJsonPath('data.status', 'submitted');

        $admin = adminAccount('uae', 'vendor_relations');
        $application = PartnerApplication::query()->sole();
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertRedirect();

        $agency = Vendor::query()->where('type', 'travel')->sole();
        $user = User::query()->where('email', 'sara@example.com')->sole();
        expect($agency->store_name)->toBe('Nile Tours')->and($agency->logo_path)->toBe($application->logo_path)
            ->and($user->memberships()->where('role', MembershipRole::TravelAgency)->where('tenant_id', $agency->id)->exists())->toBeTrue()
            ->and(ContractSignature::query()->sole()->signer_type)->toBe(SignerType::Agency)
            ->and(Document::query()->sole()->owner_type)->toBe('vendor');

        $this->actingAs($user)->get(onPanel('travel-agency', 'packages'))->assertOk();
    });

    it('validates the marketer and broker profiles and creates active marketers on approval', function () {
        requiredDocument('marketer', 'national_id');
        [$number, $token] = openApplication('marketer');
        $headers = ['X-Application-Token' => $token];

        $this->postJson(applicationsUrl("{$number}/steps/profile"), ['job_types' => ['broker'], 'display_name' => 'Omar Tech', 'whatsapp_phone' => '0501234567'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('job_types.0', 'error.fields');
        $this->postJson(applicationsUrl("{$number}/steps/profile"), ['job_types' => ['influencer', 'affiliate'], 'display_name' => 'Omar Tech', 'whatsapp_phone' => '0501234567', 'socials' => [['network' => 'instagram', 'handle' => '@omar.tech']], 'followers' => 214000], $headers)
            ->assertOk()->assertJsonPath('data.next_step', 'documents')->assertJsonPath('data.data.profile.whatsapp_phone', '+971501234567');
        $this->post(applicationsUrl("{$number}/documents"), ['document_type_id' => DocumentType::query()->sole()->id, 'file' => UploadedFile::fake()->create('id.pdf', 20, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertCreated();
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Omar'], $headers)->assertOk();

        $admin = adminAccount('uae', 'vendor_relations');
        $id = PartnerApplication::query()->where('number', $number)->value('id');
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$id}/decide"), ['decision' => 'approve'])->assertRedirect();
        $marketer = MarketerProfile::query()->sole();
        expect($marketer->global_status)->toBe(MarketerStatus::Active)->and($marketer->job_types)->toBe(['influencer', 'affiliate'])
            ->and($marketer->followers)->toBe(214000)
            ->and(User::query()->where('email', 'sara@example.com')->sole()->hasMembership(MembershipRole::Influencer, $this->country))->toBeTrue()
            ->and(Document::query()->sole()->status)->toBe(DocumentStatus::Approved);

        [$brokerNumber, $brokerToken] = openApplication('broker', ['email' => 'broker@example.com']);
        $this->postJson(applicationsUrl("{$brokerNumber}/steps/profile"), ['display_name' => 'Gulf Homes'], ['X-Application-Token' => $brokerToken])
            ->assertStatus(422)->assertJsonValidationErrors(['licence_number', 'whatsapp_phone'], 'error.fields');
        $this->postJson(applicationsUrl("{$brokerNumber}/steps/profile"), ['display_name' => 'Gulf Homes', 'licence_number' => 'RERA-55', 'whatsapp_phone' => '+971509999999', 'areas' => ['JLT', 'Marina']], ['X-Application-Token' => $brokerToken])->assertOk();
        $this->postJson(applicationsUrl("{$brokerNumber}/steps/documents"), [], ['X-Application-Token' => $brokerToken])->assertOk();
        $this->postJson(applicationsUrl("{$brokerNumber}/submit"), ['accept' => true, 'full_name' => 'Ali'], ['X-Application-Token' => $brokerToken])->assertOk();
        $this->actingAs($admin)->post(onPanel('admin', 'applications/'.PartnerApplication::query()->where('number', $brokerNumber)->value('id').'/decide'), ['decision' => 'approve'])->assertRedirect();
        $broker = MarketerProfile::query()->where('display_name', 'Gulf Homes')->sole();
        expect($broker->job_types)->toBe(['broker'])->and($broker->details['licence_number'])->toBe('RERA-55');
    });

    it('opens an Open Souq individual seller without a trade licence number', function () {
        [$number, $token] = openApplication('classified');
        $headers = ['X-Application-Token' => $token];

        $this->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'Ali cars', 'slug' => 'ali-cars', 'business_type' => 'llc'], $headers)->assertStatus(422)->assertJsonValidationErrors('business_type', 'error.fields');
        $this->postJson(applicationsUrl("{$number}/steps/store"), ['store_name' => 'Ali cars', 'slug' => 'ali-cars', 'business_type' => 'individual'], $headers)->assertOk();
        $this->postJson(applicationsUrl("{$number}/steps/address"), ['city' => 'Dubai', 'street' => 'JLT'], $headers)->assertOk();
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Ali'], $headers)->assertOk();

        $this->actingAs(adminAccount())->post(onPanel('admin', 'applications/'.PartnerApplication::query()->value('id').'/decide'), ['decision' => 'approve'])->assertRedirect();
        $vendor = Vendor::query()->sole();
        expect($vendor->type)->toBe('classified')->and($vendor->slug)->toBe('ali-cars')
            ->and(User::query()->where('email', 'sara@example.com')->sole()->hasMembership(MembershipRole::ClassifiedSeller, $this->country))->toBeTrue();
    });

    it('registers a shipping company whose owner becomes the carrier supervisor', function () {
        requiredDocument('carrier', 'courier_licence');
        [$number, $token] = openApplication('carrier');
        $headers = ['X-Application-Token' => $token];

        $this->postJson(applicationsUrl("{$number}/steps/company"), ['legal_name' => 'FastLane Couriers LLC', 'services' => ['teleport']], $headers)->assertStatus(422)->assertJsonValidationErrors(['licence_number', 'services.0'], 'error.fields');
        $this->postJson(applicationsUrl("{$number}/steps/company"), ['legal_name' => 'FastLane Couriers LLC', 'licence_number' => 'RTA-CR-55021', 'coverage' => ['Dubai', 'Sharjah'], 'fleet_size' => 51, 'services' => ['same_day', 'cod']], $headers)->assertOk();
        $this->post(applicationsUrl("{$number}/documents"), ['document_type_id' => DocumentType::query()->sole()->id, 'file' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertCreated();
        $this->postJson(applicationsUrl("{$number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertOk();

        $this->actingAs(adminAccount())->post(onPanel('admin', 'applications/'.PartnerApplication::query()->value('id').'/decide'), ['decision' => 'approve'])->assertRedirect();
        $carrier = Carrier::query()->sole();
        $user = User::query()->where('email', 'sara@example.com')->sole();
        expect($carrier->owner_user_id)->toBe($user->id)->and($carrier->licence_number)->toBe('RTA-CR-55021')->and($carrier->details['fleet_size'])->toBe(51)
            ->and($user->memberships()->where('role', MembershipRole::CarrierSupervisor)->where('tenant_type', 'carrier')->where('tenant_id', $carrier->id)->exists())->toBeTrue()
            ->and(Document::query()->sole()->owner_type)->toBe('carrier');
        Notification::assertSentTo($user, PartnerWelcomeNotification::class, fn (PartnerWelcomeNotification $mail): bool => str_contains($mail->panelUrl, 'carrier.') && $mail->temporaryPassword === null);
        $this->actingAs($user)->get(onPanel('carrier'))->assertOk();
    });
});

describe('admin review', function () {
    it('lists the queue in Oman time and approves into an active store with the password chosen in the wizard', function () {
        publishedAgreement(ContractKind::SellerPlatform, $this->country);
        [$application] = submittedVendorApplication();
        $admin = adminAccount('uae', 'vendor_relations');

        $this->actingAs($admin)->get(onPanel('admin', 'applications'))->assertOk()->assertSee($application->number)->assertSee('Docs 0/1');
        $this->actingAs($admin)->get(onPanel('admin', "applications/{$application->id}"))->assertOk()->assertSee('SoundMax Trading LLC')->assertSee('licence.pdf')->assertSee('Seller Agreement v1 signed by Sara Al Mansoori', false);
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/assign"))->assertRedirect();
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'start'])->assertRedirect();
        expect($application->refresh()->status)->toBe(ApplicationStatus::UnderReview)->and($application->assigned_to_user_id)->toBe($admin->id);

        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertRedirect();
        $application->refresh();
        $vendor = Vendor::query()->sole();
        $user = User::query()->where('email', 'sara@example.com')->sole();

        expect($application->status)->toBe(ApplicationStatus::Approved)->and($application->tenant_id)->toBe($vendor->id)->and($application->password_hash)->toBeNull()
            ->and($vendor->type)->toBe('product')->and($vendor->isActive())->toBeTrue()->and($vendor->business_details['store']['cr_number'])->toBe('DED-1187742')
            ->and(Hash::check('secret-pass-1', $user->password))->toBeTrue()
            ->and($user->memberships()->where('role', MembershipRole::ProductVendor)->where('tenant_id', $vendor->id)->where('status', 'active')->exists())->toBeTrue()
            ->and($vendor->hasAcceptedSellerAgreement())->toBeTrue()
            ->and(Document::query()->sole())->owner_type->toBe('vendor')->status->toBe(DocumentStatus::Approved)
            ->and($application->transitions()->pluck('to_status')->all())->toBe(['draft', 'submitted', 'submitted', 'under_review', 'approved']);
        Notification::assertSentTo($user, PartnerWelcomeNotification::class, fn (PartnerWelcomeNotification $mail): bool => ! $mail->existingAccount && str_contains($mail->panelUrl, 'partner.'));

        $this->actingAs($admin)->get(onPanel('admin', "applications/{$application->id}"))->assertOk()->assertSee('Audit trail')->assertSee('vendor #'.$vendor->id);
        app('auth')->forgetGuards();
        $this->actingAs($user)->get(onPanel('partner'))->assertOk();
    });

    it('requests changes to a step and a document, then takes the resubmission', function () {
        [$application, $oldToken] = submittedVendorApplication();
        $admin = adminAccount('uae', 'vendor_relations');
        $document = Document::query()->sole();

        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'changes'])->assertSessionHasErrors('action');
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'changes', 'reason' => 'Licence is blurry; fix the address', 'steps' => ['address'], 'document_ids' => [$document->id]])->assertRedirect();

        $application->refresh();
        expect($application->status)->toBe(ApplicationStatus::ChangesRequested)->and($application->hasCompleted('address'))->toBeFalse()
            ->and($application->hasCompleted('documents'))->toBeFalse()->and($document->refresh()->status)->toBe(DocumentStatus::Rejected);
        $this->getJson(applicationsUrl($application->number), ['X-Application-Token' => $oldToken])->assertNotFound();

        $token = null;
        Notification::assertSentOnDemand(RequestNotification::class, function (RequestNotification $mail) use (&$token): bool {
            if ($mail->kind !== 'partner_application.changes_requested') {
                return false;
            }
            preg_match('/token=([A-Za-z0-9]+)/', implode(' ', $mail->lines), $match);
            $token = $match[1];

            return true;
        });
        $headers = ['X-Application-Token' => $token];
        $this->getJson(applicationsUrl($application->number), $headers)->assertOk()->assertJsonPath('data.requested_changes.note', 'Licence is blurry; fix the address')->assertJsonPath('data.next_step', 'address');
        $this->postJson(applicationsUrl("{$application->number}/steps/address"), ['city' => 'Dubai', 'street' => 'Business Bay'], $headers)->assertOk();
        $this->postJson(applicationsUrl("{$application->number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertStatus(422);
        $this->post(applicationsUrl("{$application->number}/documents"), ['document_type_id' => $document->document_type_id, 'file' => UploadedFile::fake()->create('licence2.pdf', 100, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertCreated();
        $this->postJson(applicationsUrl("{$application->number}/submit"), ['accept' => true, 'full_name' => 'Sara'], $headers)->assertOk()->assertJsonPath('data.status', 'submitted');
        expect($application->refresh()->transitions()->get()->last()->reason)->toBe('Resubmitted with changes');
    });

    it('refuses approval while a mandatory document is rejected and rejects with a reason', function () {
        [$application] = submittedVendorApplication();
        $admin = adminAccount('uae', 'vendor_relations');
        $document = Document::query()->sole();

        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/documents/{$document->id}"), ['decision' => 'reject', 'reason' => 'Expired licence'])->assertRedirect();
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertSessionHasErrors('action');
        expect(Vendor::query()->count())->toBe(0);

        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'reject'])->assertSessionHasErrors('action');
        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'reject', 'reason' => 'Trade licence expired'])->assertRedirect();
        expect($application->refresh()->status)->toBe(ApplicationStatus::Rejected)->and($application->decision_reason)->toBe('Trade licence expired')
            ->and(User::query()->where('email', 'sara@example.com')->exists())->toBeFalse();
        Notification::assertSentOnDemand(RequestNotification::class, fn (RequestNotification $mail): bool => $mail->kind === 'partner_application.rejected' && str_contains(implode(' ', $mail->lines), 'Trade licence expired'));

        $this->actingAs($admin)->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertSessionHasErrors('action');
        $this->postJson(applicationsUrl(), applicantAccount('vendor'))->assertCreated();
    });

    it('adds the workspace to an existing account and authorises reviewers only', function () {
        $existing = User::factory()->create(['email' => 'sara@example.com', 'password' => 'old-password-9']);
        [$application] = submittedVendorApplication();

        $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'applications'))->assertForbidden();
        $this->actingAs(adminAccount('uae', 'support'))->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs(adminAccount('omn', 'vendor_relations'))->post(onPanel('admin', "applications/{$application->id}/decide?country=uae"), ['decision' => 'approve'])->assertNotFound();
        $this->actAsCountry('uae');

        $this->actingAs(adminAccount('uae', 'vendor_relations'))->post(onPanel('admin', "applications/{$application->id}/decide"), ['decision' => 'approve'])->assertRedirect();
        expect(Hash::check('old-password-9', $existing->refresh()->password))->toBeTrue()
            ->and($existing->hasMembership(MembershipRole::ProductVendor, $this->country))->toBeTrue()
            ->and(User::query()->where('email', 'sara@example.com')->count())->toBe(1);
        Notification::assertSentTo($existing, PartnerWelcomeNotification::class, fn (PartnerWelcomeNotification $mail): bool => $mail->existingAccount);
    });
});
