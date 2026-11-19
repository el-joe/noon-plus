<?php

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Ops apps follow-ups (2026-11-19): documents in the vendor, marketer,
| supervisor and travel agency apps; FBN enrolment and carton labels in the
| vendor app; in-app travel promotion purchase with an app return link.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Notification::fake();
    Storage::fake('local');
    Storage::fake('public');
});

function opsDocType(string $businessType, string $code, bool $expiry = false, string $requirement = 'mandatory', int $maxMb = 2): DocumentType
{
    return DocumentType::query()->create([
        'country_id' => app(CountryContext::class)->current()->id, 'code' => $code, 'name_en' => ucfirst(str_replace('_', ' ', $code)), 'name_ar' => 'وثيقة',
        'business_type' => $businessType, 'requirement' => $requirement, 'has_expiry' => $expiry, 'max_mb' => $maxMb,
    ]);
}

function opsToken(User $user, string $role): string
{
    app('auth')->forgetGuards();

    return $user->createToken('ops', ["ops:{$role}"])->plainTextToken;
}

/**
 * @return array{0: User, 1: Vendor}
 */
function opsTravelAgency(): array
{
    $user = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'store_name' => 'Gulf Holidays', 'owner_user_id' => $user->id]);
    $user->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => app(CountryContext::class)->current()->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id]);

    return [$user, $agency];
}

describe('ops documents', function () {
    it('lists the store requirements and uploads, validates expiry and size, and re-uploads a rejected document', function () {
        [$seller, $vendor] = sellerAccount();
        $licence = opsDocType('vendor', 'trade_licence', expiry: true);
        $vat = opsDocType('vendor', 'vat_certificate', requirement: 'optional');
        opsDocType('marketer', 'id_card');
        $url = '/api/v1/uae-en/ops/vendor/documents';
        $this->withToken(opsToken($seller, 'vendor'));

        $this->getJson($url)->assertOk()
            ->assertJsonCount(2, 'data.requirements')->assertJsonPath('data.requirements.0.code', 'trade_licence')
            ->assertJsonPath('data.requirements.0.has_expiry', true)->assertJsonPath('data.requirements.0.max_mb', 2)
            ->assertJsonPath('data.missing', [$licence->id])->assertJsonPath('meta.business_type', 'vendor');

        $this->post($url, ['document_type_id' => $licence->id, 'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('valid_until', 'error.fields');
        $this->post($url, ['document_type_id' => $licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence.pdf', 3000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file', 'error.fields');
        $this->post($url, ['document_type_id' => $licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file', 'error.fields');

        $id = $this->post($url, ['document_type_id' => $licence->id, 'valid_until' => now()->addDays(20)->toDateString(), 'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.type', 'Trade licence')->json('data.id');
        $this->post($url, ['document_type_id' => $vat->id, 'file' => UploadedFile::fake()->image('vat.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.missing', [])->assertJsonCount(2, 'data.uploads');

        // Rejected: reason shown, re-upload goes back to pending on the same row; approved with 20 days left: expiring soon.
        $document = Document::query()->findOrFail($id);
        app(Documents::class)->reject($document, 1, 'Blurred scan');
        $this->getJson($url)->assertJsonPath('data.uploads.1.status', 'rejected')->assertJsonPath('data.uploads.1.rejection_reason', 'Blurred scan')->assertJsonPath('data.uploads.1.can_reupload', true)
            ->assertJsonPath('data.missing', [$licence->id]);
        $this->post($url, ['document_type_id' => $licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence-2.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.rejection_reason', null);
        app(Documents::class)->approve($document->refresh(), 1);
        $document->forceFill(['valid_until' => now()->addDays(20)->toDateString()])->save();
        $this->getJson($url)->assertJsonPath('data.uploads.1.expiring_soon', true)->assertJsonPath('data.uploads.1.expires_in_days', 20)->assertJsonPath('data.uploads.1.can_reupload', false);

        $this->get("{$url}/{$id}/file")->assertOk()->assertDownload('licence-2.pdf');

        // Another store's file and a type of another business are refused.
        [, $other] = sellerAccount('uae', ['store_name' => 'Other']);
        $theirs = app(Documents::class)->upload($licence, 'vendor', $other->id, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), null, null, now()->addYear()->toDateString());
        $this->get("{$url}/{$theirs->id}/file")->assertNotFound();
        $this->post($url, ['document_type_id' => DocumentType::query()->where('code', 'id_card')->value('id'), 'file' => UploadedFile::fake()->image('id.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('document_type_id', 'error.fields');
    });

    it('uses each workspace\'s business type in the marketer, supervisor and travel apps', function () {
        $marketerType = opsDocType('marketer', 'id_card');
        $brokerType = opsDocType('broker', 'broker_licence');
        $carrierType = opsDocType('carrier', 'transport_licence', expiry: true);
        $agencyType = opsDocType('agency', 'tourism_licence');

        [$marketerUser, $marketer] = marketerAccount(['affiliate']);
        $this->withToken(opsToken($marketerUser, 'marketer'))->getJson('/api/v1/uae-en/ops/marketer/documents')->assertOk()
            ->assertJsonPath('data.requirements.0.id', $marketerType->id)->assertJsonPath('meta.business_type', 'marketer');
        $this->post('/api/v1/uae-en/ops/marketer/documents', ['document_type_id' => $marketerType->id, 'file' => UploadedFile::fake()->image('id.png')], ['Accept' => 'application/json'])->assertCreated();
        expect(Document::query()->where('owner_type', 'marketer')->where('owner_id', $marketer->id)->sole()->status)->toBe(DocumentStatus::Pending);

        [$brokerUser] = marketerAccount(['broker']);
        $this->withToken(opsToken($brokerUser, 'marketer'))->getJson('/api/v1/uae-en/ops/marketer/documents')->assertOk()
            ->assertJsonPath('data.requirements.0.id', $brokerType->id)->assertJsonPath('meta.business_type', 'broker');

        $supervisor = User::factory()->create();
        $carrier = Carrier::query()->create(['code' => 'swift', 'name' => 'Swift', 'status' => 'active', 'owner_user_id' => $supervisor->id]);
        $supervisor->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);
        $this->withToken(opsToken($supervisor, 'supervisor'))->getJson('/api/v1/uae-en/ops/supervisor/documents')->assertOk()
            ->assertJsonPath('data.requirements.0.id', $carrierType->id)->assertJsonPath('data.missing', [$carrierType->id]);
        $this->post('/api/v1/uae-en/ops/supervisor/documents', ['document_type_id' => $carrierType->id, 'valid_until' => now()->addMonths(6)->toDateString(), 'file' => UploadedFile::fake()->create('t.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.valid_until', now()->addMonths(6)->toDateString());
        expect(Document::query()->where('owner_type', 'carrier')->where('owner_id', $carrier->id)->exists())->toBeTrue();

        [$agencyUser, $agency] = opsTravelAgency();
        $this->withToken(opsToken($agencyUser, 'travel_agency'))->getJson('/api/v1/uae-en/ops/travel/documents')->assertOk()
            ->assertJsonPath('data.requirements.0.id', $agencyType->id)->assertJsonPath('meta.business_type', 'agency');
        $this->post('/api/v1/uae-en/ops/travel/documents', ['document_type_id' => $agencyType->id, 'file' => UploadedFile::fake()->create('l.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        expect(Document::query()->where('owner_type', 'vendor')->where('owner_id', $agency->id)->exists())->toBeTrue();

        // A vendor token cannot reach another role's documents.
        [$seller] = sellerAccount();
        $this->withToken(opsToken($seller, 'vendor'))->getJson('/api/v1/uae-en/ops/travel/documents')->assertForbidden();
    });
});

describe('vendor app FBN', function () {
    it('enrols a listing in FBN, refuses leaving with stock, leaves when empty and downloads the carton labels PDF', function () {
        $warehouse = Warehouse::query()->create(['code' => 'DXB-1', 'name_en' => 'Warehouse DXB-1', 'name_ar' => 'مستودع', 'city' => 'Dubai', 'address' => 'Jebel Ali', 'is_active' => true]);
        [$seller, $vendor] = sellerAccount();
        $listing = sellableListing('100.00', stock: 4, vendor: $vendor);
        $stocked = sellableListing('50.00', stock: 0, vendor: $vendor);
        app(FbnEnrolment::class)->enrol($stocked, null);
        app(WarehouseStockService::class)->receive($warehouse, $stocked->refresh()->load('variant'), 3, 0, 'A-01', 'test', 1, null);
        $this->withToken(opsToken($seller, 'vendor'));

        $this->getJson('/api/v1/uae-en/ops/vendor/fbn/listings')->assertOk()->assertJsonPath('meta.has_warehouses', true)
            ->assertJsonPath('data.0.listing_id', $listing->id)->assertJsonPath('data.0.fulfilment', 'fbm')->assertJsonPath('data.0.can_enrol', true)
            ->assertJsonPath('data.1.fulfilment', 'fbn')->assertJsonPath('data.1.warehouse_units', 3)->assertJsonPath('data.1.can_leave', false);
        $this->getJson('/api/v1/uae-en/ops/vendor/fbn/listings?fulfilment=fbm')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/listings/{$listing->id}/enrol")->assertOk()->assertJsonPath('data.fulfilment', 'fbn')->assertJsonPath('data.can_leave', true);
        expect($listing->refresh()->fulfilment)->toBe('fbn');
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/listings/{$stocked->id}/leave")->assertStatus(422)->assertJsonPath('error.code', 'transition_refused');

        // Inbound plan → labels PDF through the vendor API.
        $id = $this->postJson('/api/v1/uae-en/ops/vendor/fbn/inbound', ['warehouse_id' => $warehouse->id, 'cartons' => 2, 'lines' => [['listing_id' => $listing->id, 'quantity' => 4, 'cartons' => 2]]])
            ->assertCreated()->assertJsonPath('data.labels_path', null)->json('data.id');
        $this->get("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/labels.pdf")->assertStatus(409)->assertJsonPath('error.code', 'transition_refused');
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/submit")->assertOk()->assertJsonPath('data.labels_path', "ops/vendor/fbn/inbound/{$id}/labels.pdf");
        $this->get("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/labels.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Leaving with an open inbound plan is refused; after cancelling it the empty listing leaves.
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/listings/{$listing->id}/leave")->assertStatus(422);
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/cancel")->assertOk();
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/listings/{$listing->id}/leave")->assertOk()->assertJsonPath('data.fulfilment', 'fbm');

        // Another store's listing / plan.
        [$other, $otherVendor] = sellerAccount('uae', ['store_name' => 'Other']);
        $theirs = sellableListing('10.00', vendor: $otherVendor);
        $this->postJson("/api/v1/uae-en/ops/vendor/fbn/listings/{$theirs->id}/enrol")->assertNotFound();
        $this->withToken(opsToken($other, 'vendor'))->get("/api/v1/uae-en/ops/vendor/fbn/inbound/{$id}/labels.pdf")->assertNotFound();
    });
});

describe('travel app in-app promotion purchase', function () {
    beforeEach(function () {
        [$this->agencyUser, $this->agency] = opsTravelAgency();
        $this->trip = new TravelPackage(['vendor_id' => $this->agency->id, 'slug' => 'baku-'.Str::lower(Str::random(4)), 'title_en' => 'Baku 4 nights', 'title_ar' => 'باكو', 'trip_type' => 'leisure', 'destination_city' => 'Baku', 'nights' => 4]);
        $this->trip->forceFill(['status' => PackageStatus::Active, 'approved_at' => now()->subDay()])->save();
        $this->product = TravelPromotionProduct::query()->create(['ad_slot_id' => app(TravelPromotions::class)->slot()->id, 'code' => 'travel-7d', 'name_en' => '7 days at the top', 'name_ar' => '7 أيام', 'duration_days' => 7, 'price' => '105.00']);
        $this->withToken(opsToken($this->agencyUser, 'travel_agency'));
    });

    it('lists the options, buys with an app return link and shows the active promotion after the deep-link return', function () {
        $this->getJson('/api/v1/uae-en/ops/travel/promotions/options')->assertOk()
            ->assertJsonPath('data.packages.0.id', $this->trip->id)->assertJsonPath('data.products.0.id', $this->product->id)
            ->assertJsonPath('data.products.0.price.amount', '105.00')->assertJsonPath('data.products.0.vat.amount', '5.00');

        $buy = $this->postJson('/api/v1/uae-en/ops/travel/promotions', ['package_id' => $this->trip->id, 'product_id' => $this->product->id])->assertCreated()
            ->assertJsonPath('data.promotion.status', 'pending_payment')->assertJsonPath('data.result', 'pending')->assertJsonPath('data.payment.status', 'pending');
        expect($buy->json('data.payment.redirect_url'))->toStartWith('https://pay.fake.test/');
        $promotionId = $buy->json('data.promotion.id');
        $payment = Payment::query()->where('reference', $buy->json('data.payment.reference'))->sole();
        expect($payment->return_url)->toBe('nawytravel://promotions/return');

        // The gateway return lands on the app deep link (verified by the app, never trusted).
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")
            ->assertRedirect("nawytravel://promotions/return?payment={$payment->reference}&status=captured");

        $this->postJson("/api/v1/uae-en/ops/travel/promotions/{$promotionId}/verify", ['payment' => $payment->reference])->assertOk()
            ->assertJsonPath('data.result', 'active')->assertJsonPath('data.promotion.status', 'active')->assertJsonPath('data.promotion.can_pay', false);
        $this->getJson('/api/v1/uae-en/ops/travel/promotions')->assertOk()->assertJsonPath('data.0.status', 'active')->assertJsonPath('meta.in_app', true);
    });

    it('shows a failed payment and pays again in the app; a declined start fails at once', function () {
        $buy = $this->postJson('/api/v1/uae-en/ops/travel/promotions', ['package_id' => $this->trip->id, 'product_id' => $this->product->id])->assertCreated();
        $promotionId = $buy->json('data.promotion.id');
        $payment = Payment::query()->where('reference', $buy->json('data.payment.reference'))->sole();

        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Failed);
        $this->postJson("/api/v1/uae-en/ops/travel/promotions/{$promotionId}/verify")->assertOk()->assertJsonPath('data.result', 'failed')->assertJsonPath('data.promotion.can_pay', true)
            ->assertJsonPath('data.payment.redirect_url', null);

        $again = $this->postJson("/api/v1/uae-en/ops/travel/promotions/{$promotionId}/pay")->assertCreated()->assertJsonPath('data.result', 'pending');
        expect($again->json('data.payment.reference'))->not->toBe($payment->reference);
        $this->getJson("/api/v1/uae-en/ops/travel/promotions/{$promotionId}")->assertOk()->assertJsonPath('data.payment.reference', $again->json('data.payment.reference'));

        FakeGateway::failNext('create');
        $failed = $this->postJson('/api/v1/uae-en/ops/travel/promotions', ['package_id' => $this->trip->id, 'product_id' => $this->product->id])->assertCreated()
            ->assertJsonPath('data.payment.status', 'failed')->assertJsonPath('data.payment.redirect_url', null)->assertJsonPath('data.result', 'failed');
        expect($failed->json('data.payment.failure_message'))->not->toBeEmpty();
    });

    it('refuses another agency\'s trip or promotion and a trip that is not live', function () {
        [, $other] = opsTravelAgency();
        $theirs = TravelPromotion::query()->forceCreate(['number' => 'TP-X', 'vendor_id' => $other->id, 'travel_package_id' => $this->trip->id, 'travel_promotion_product_id' => $this->product->id,
            'ad_slot_id' => $this->product->ad_slot_id, 'duration_days' => 7, 'currency' => 'AED', 'amount' => '105', 'bought_by_user_id' => $this->agencyUser->id, 'status' => TravelPromotionStatus::PendingPayment]);
        $this->postJson("/api/v1/uae-en/ops/travel/promotions/{$theirs->id}/verify")->assertNotFound();

        $draft = new TravelPackage(['vendor_id' => $this->agency->id, 'slug' => 'draft-'.Str::random(4), 'title_en' => 'Draft', 'title_ar' => 'مسودة', 'trip_type' => 'leisure', 'destination_city' => 'Baku', 'nights' => 2]);
        $draft->forceFill(['status' => PackageStatus::Draft])->save();
        $this->postJson('/api/v1/uae-en/ops/travel/promotions', ['package_id' => $draft->id, 'product_id' => $this->product->id])->assertStatus(422)->assertJsonPath('error.code', 'transition_refused');
        $this->getJson('/api/v1/uae-en/ops/travel/promotions/options')->assertJsonCount(1, 'data.packages');
    });
});
