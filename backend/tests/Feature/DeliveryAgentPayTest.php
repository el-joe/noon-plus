<?php

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AgentWithdrawalStatus;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Models\DeliveryWithdrawal;
use App\Domains\Delivery\Models\Shipment;
use App\Domains\Delivery\Support\AgentPay;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Delivery\Support\ParcelBarcode;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Notification::fake();
    $this->user = User::factory()->create();
    $this->user->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $this->country->id]);
    $this->agent = DeliveryAgent::query()->create(['user_id' => $this->user->id, 'name' => 'Rashid', 'status' => 'active']);
    $this->token = $this->user->createToken('phone', ['delivery'])->plainTextToken;
    $this->api = '/api/v1/uae-en/delivery/';
});

/**
 * A finished stop of the agent (`$status` at `$at`).
 */
function paidStop(DeliveryAgent $agent, AssignmentStatus $status, mixed $at): DeliveryAssignment
{
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id])->save();
    $assignment = app(DeliveryDispatch::class)->assign($subOrder->refresh(), $agent, ActorType::Admin, 1);
    $assignment->forceFill(['status' => $status, 'delivered_at' => $status === AssignmentStatus::Delivered ? $at : null, 'failed_at' => $status === AssignmentStatus::Failed ? $at : null, 'updated_at' => $at])->saveQuietly();

    return $assignment;
}

function agentRates(string $perStop = '6.00', ?string $perFailed = '1.50'): void
{
    $country = app(CountryContext::class)->current();
    app(SettingsService::class)->set('delivery.pay_per_stop', $perStop, $country);
    if ($perFailed !== null) {
        app(SettingsService::class)->set('delivery.pay_per_failed_attempt', $perFailed, $country);
    }
}

function fundAgentWallet(DeliveryAgent $agent, string $amount = '100.00'): void
{
    agentRates($amount, null);
    paidStop($agent, AssignmentStatus::Delivered, now()->subWeeks(2));
    app(AgentPay::class)->issueDue(app(CountryContext::class)->current());
}

const VALID_IBAN = 'AE070331234567890123456';

describe('documents', function () {
    beforeEach(function () {
        $this->licence = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'driving_licence', 'name_en' => 'Driving licence', 'name_ar' => 'رخصة', 'business_type' => 'delivery_agent', 'requirement' => 'mandatory', 'has_expiry' => true, 'max_mb' => 2]);
        $this->photo = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'vehicle_photo', 'name_en' => 'Vehicle photo', 'name_ar' => 'صورة', 'business_type' => 'delivery_agent', 'requirement' => 'optional']);
    });

    it('uploads a PDF document from the app, needs the expiry date and the size limit of the type', function () {
        $this->withToken($this->token)->getJson($this->api.'me/documents')->assertOk()
            ->assertJsonPath('data.requirements.0.has_expiry', true)->assertJsonPath('data.requirements.0.max_mb', 2)
            ->assertJsonPath('data.missing', [$this->licence->id]);

        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->licence->id, 'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['valid_until'], 'error.fields');
        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence.pdf', 3000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file'], 'error.fields');
        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file'], 'error.fields');

        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->licence->id, 'valid_until' => now()->addYear()->toDateString(), 'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.original_name', 'licence.pdf')->assertJsonPath('data.expiring_soon', false);
        $document = Document::query()->sole();
        expect($document->owner_type)->toBe('delivery_agent')->and($document->owner_id)->toBe($this->agent->id);
        Storage::disk('local')->assertExists($document->file_path);

        $this->withToken($this->token)->getJson($this->api.'me/documents')->assertJsonPath('data.missing', []);
        $this->withToken($this->token)->get($this->api."me/documents/{$document->id}/file")->assertOk()->assertDownload('licence.pdf');
    });

    it('takes a camera photo, refuses document types of other owners and keeps other agents\' files private', function () {
        $vendorType = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'trade_licence', 'name_en' => 'Trade licence', 'name_ar' => 'رخصة', 'business_type' => 'vendor', 'requirement' => 'mandatory']);
        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $vendorType->id, 'file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['document_type_id'], 'error.fields');
        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->photo->id, 'file' => UploadedFile::fake()->image('van.jpg')], ['Accept' => 'application/json'])->assertCreated();

        $other = DeliveryAgent::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'status' => 'active']);
        $theirs = app(Documents::class)->upload($this->photo, 'delivery_agent', $other->id, UploadedFile::fake()->image('theirs.jpg'), $other->user_id);
        $this->withToken($this->token)->get($this->api."me/documents/{$theirs->id}/file")->assertNotFound();
    });

    it('flags approved documents that expire soon and sends a rejected one back to review on re-upload', function () {
        $documents = app(Documents::class);
        $licence = $documents->upload($this->licence, 'delivery_agent', $this->agent->id, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $this->user->id, null, now()->addDays(12)->toDateString());
        $documents->approve($licence, 1);
        $photo = $documents->upload($this->photo, 'delivery_agent', $this->agent->id, UploadedFile::fake()->image('b.jpg'), $this->user->id);
        $documents->reject($photo, 1, 'Blurry');

        $response = $this->withToken($this->token)->getJson($this->api.'me/documents')->assertOk();
        $uploads = collect($response->json('data.uploads'))->keyBy('id');
        expect($uploads[$licence->id]['expiring_soon'])->toBeTrue()->and($uploads[$licence->id]['expires_in_days'])->toBe(12)
            ->and($uploads[$photo->id]['rejection_reason'])->toBe('Blurry');

        $this->withToken($this->token)->post($this->api.'me/documents', ['document_type_id' => $this->photo->id, 'file' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.id', $photo->id)->assertJsonPath('data.status', 'pending');
    });

    it('shows the carrier its drivers\' document status', function () {
        [$owner, $carrier] = (function () {
            $owner = User::factory()->create();
            $carrier = Carrier::query()->create(['code' => 'fast', 'name' => 'Fast Couriers', 'status' => 'active', 'owner_user_id' => $owner->id]);
            $owner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);

            return [$owner, $carrier];
        })();
        $this->agent->forceFill(['carrier_id' => $carrier->id])->save();
        $documents = app(Documents::class);
        $documents->approve($documents->upload($this->licence, 'delivery_agent', $this->agent->id, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $this->user->id, null, now()->addDays(5)->toDateString()), 1);

        $this->actingAs($owner)->get(onPanel('carrier', 'driver-documents'))->assertOk()
            ->assertSee('Rashid')->assertSee('Driving licence')->assertSee(now()->addDays(5)->toDateString())->assertSee('Missing');
    });
});

describe('payslips', function () {
    it('issues one payslip per closed week, credits the wallet in the ledger and serves it as JSON and PDF', function () {
        agentRates();
        $monday = CarbonImmutable::now($this->country->timezone)->startOfWeek()->subWeek()->setTime(10, 0)->utc();
        $stops = [paidStop($this->agent, AssignmentStatus::Delivered, $monday), paidStop($this->agent, AssignmentStatus::Delivered, $monday->addDays(2)), paidStop($this->agent, AssignmentStatus::Failed, $monday->addDays(3))];
        paidStop($this->agent, AssignmentStatus::Delivered, now()); // this week: next payslip
        paidStop($this->agent, AssignmentStatus::Assigned, $monday); // not finished

        expect(app(AgentPay::class)->issueDue($this->country))->toBe(1)
            ->and(app(AgentPay::class)->issueDue($this->country))->toBe(0);
        $payslip = DeliveryPayslip::query()->sole();
        expect($payslip->total)->toBe('13.5000')->and($payslip->delivered)->toBe(2)->and($payslip->failed)->toBe(1)
            ->and($payslip->period_start->toDateString())->toBe($monday->setTimezone($this->country->timezone)->toDateString())
            ->and($payslip->lines()->pluck('delivery_assignment_id')->sort()->values()->all())->toBe(collect($stops)->pluck('id')->sort()->values()->all())
            ->and(ledgerBalance(AccountRef::agentWallet($this->user->id)))->toBe('13.50')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::DeliveryPayExpense)))->toBe('13.50');
        assertLedgerBalanced();

        $this->withToken($this->token)->getJson($this->api.'me/payslips')->assertOk()
            ->assertJsonPath('data.0.number', $payslip->number)->assertJsonPath('data.0.total', ['amount' => '13.50', 'currency' => 'AED'])
            ->assertJsonPath('data.0.per_stop', ['amount' => '6.00', 'currency' => 'AED']);
        $this->withToken($this->token)->getJson($this->api."me/payslips/{$payslip->number}")->assertOk()
            ->assertJsonCount(3, 'data.lines')->assertJsonPath('data.lines.2.outcome', 'failed')->assertJsonPath('data.lines.2.amount', ['amount' => '1.50', 'currency' => 'AED']);
        $pdf = $this->withToken($this->token)->get($this->api."me/payslips/{$payslip->number}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($pdf->headers->get('Content-Disposition'))->toContain('payslip-')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

        $other = DeliveryAgent::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'status' => 'active']);
        $otherToken = User::find($other->user_id)->createToken('p', ['delivery'])->plainTextToken;
        User::find($other->user_id)->memberships()->create(['role' => MembershipRole::DeliveryAgent, 'country_id' => $this->country->id]);
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->getJson($this->api."me/payslips/{$payslip->number}")->assertNotFound();
    });

    it('issues nothing while the country has no pay rate', function () {
        paidStop($this->agent, AssignmentStatus::Delivered, now()->subWeeks(2));
        expect(app(AgentPay::class)->issueDue($this->country))->toBe(0);
        $this->artisan('nawy:delivery:issue-payslips')->assertSuccessful();
        expect(DeliveryPayslip::query()->count())->toBe(0);
    });

    it('shows payslips, wallet and the withdraw form in the delivery panel without the old "coming" note', function () {
        fundAgentWallet($this->agent);
        $payslip = DeliveryPayslip::query()->sole();

        $this->actingAs($this->user)->get(onPanel('delivery', 'earnings'))->assertOk()
            ->assertSee($payslip->number)->assertSee('Withdraw to bank')->assertDontSee('are coming');
        $this->actingAs($this->user)->get(onPanel('delivery', "payslips/{$payslip->number}"))->assertOk()->assertSee($payslip->number);
        $this->actingAs($this->user)->get(onPanel('delivery', "payslips/{$payslip->number}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->user)->post(onPanel('delivery', 'withdrawals'), ['amount' => '60', 'account_holder' => 'Rashid', 'bank_name' => 'ENBD', 'iban' => VALID_IBAN])->assertRedirect()->assertSessionHasNoErrors();
        $withdrawal = DeliveryWithdrawal::query()->sole();
        $this->actingAs($this->user)->get(onPanel('delivery', 'earnings'))->assertSee($withdrawal->number)->assertSee('••••3456');
        $this->actingAs($this->user)->post(onPanel('delivery', "withdrawals/{$withdrawal->number}/cancel"))->assertRedirect();
        expect($withdrawal->refresh()->status)->toBe(AgentWithdrawalStatus::Cancelled);
    });
});

describe('withdrawals', function () {
    it('reserves the amount, refuses invalid requests and shows the wallet', function () {
        fundAgentWallet($this->agent);
        app(SettingsService::class)->set('delivery.withdrawal_minimum', '20', $this->country);

        $this->withToken($this->token)->getJson($this->api.'me/wallet')->assertOk()
            ->assertJsonPath('data.available', ['amount' => '100.00', 'currency' => 'AED'])
            ->assertJsonPath('data.minimum', ['amount' => '20.00', 'currency' => 'AED'])
            ->assertJsonPath('data.can_withdraw', true)->assertJsonPath('data.paid_by', 'nawy');

        $body = ['account_holder' => 'Rashid Ali', 'bank_name' => 'ENBD', 'iban' => VALID_IBAN];
        $this->withToken($this->token)->postJson($this->api.'me/withdrawals', ['amount' => '60'] + ['iban' => 'AE00123'] + $body)->assertUnprocessable()->assertJsonValidationErrors(['iban'], 'error.fields');
        $this->withToken($this->token)->postJson($this->api.'me/withdrawals', ['amount' => '10'] + $body)->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');
        $this->withToken($this->token)->postJson($this->api.'me/withdrawals', ['amount' => '150'] + $body)->assertUnprocessable()->assertJsonPath('error.code', 'transition_refused');

        $this->withToken($this->token)->postJson($this->api.'me/withdrawals', ['amount' => '60.00', 'iban' => 'ae07 0331 2345 6789 0123 456'] + $body)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payout.iban_last4', '3456')->assertJsonPath('data.amount', ['amount' => '60.00', 'currency' => 'AED']);
        $withdrawal = DeliveryWithdrawal::query()->sole();
        expect($withdrawal->payout_details['iban'])->toBe('AE070331234567890123456')
            ->and(DB::connection($withdrawal->getConnectionName())->table('delivery_withdrawals')->value('payout_details'))->not->toContain('AE07')
            ->and(ledgerBalance(AccountRef::agentWallet($this->user->id)))->toBe('40.00')
            ->and(ledgerBalance(AccountRef::agentWallet($this->user->id, WalletBucket::Reserved)))->toBe('60.00');

        $this->withToken($this->token)->postJson($this->api.'me/withdrawals', ['amount' => '20'] + $body)->assertUnprocessable();
        $this->withToken($this->token)->getJson($this->api.'me/wallet')->assertJsonPath('data.can_withdraw', false)
            ->assertJsonPath('data.reserved', ['amount' => '60.00', 'currency' => 'AED'])->assertJsonPath('data.last_payout.bank_name', 'ENBD');

        $this->withToken($this->token)->postJson($this->api."me/withdrawals/{$withdrawal->number}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect(ledgerBalance(AccountRef::agentWallet($this->user->id)))->toBe('100.00');
        assertLedgerBalanced();
    });

    it('needs two different finance admins to approve and pay a Nawy fleet withdrawal', function () {
        fundAgentWallet($this->agent);
        $withdrawal = app(AgentPay::class)->requestWithdrawal($this->agent, app(MoneyFactory::class)->of('80', 'AED'), ['account_holder' => 'Rashid', 'bank_name' => 'ENBD', 'iban' => VALID_IBAN], $this->user->id);
        $first = adminAccount('uae', 'finance');
        $second = adminAccount('uae', 'finance');

        $this->actingAs($first)->get(onPanel('admin', 'delivery/withdrawals'))->assertOk()->assertSee($withdrawal->number)->assertSee('Four-eyes');
        $this->actingAs($first)->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'approve'])->assertRedirect();
        $this->actingAs($first)->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'pay', 'reference' => 'TRX-1'])->assertSessionHasErrors('action');
        expect($withdrawal->refresh()->status)->toBe(AgentWithdrawalStatus::Approved);

        $this->actingAs($second)->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'pay', 'reference' => 'TRX-1'])->assertRedirect()->assertSessionHasNoErrors();
        expect($withdrawal->refresh()->status)->toBe(AgentWithdrawalStatus::Paid)->and($withdrawal->reference)->toBe('TRX-1')
            ->and(ledgerBalance(AccountRef::agentWallet($this->user->id, WalletBucket::Reserved)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('-80.00');
        assertLedgerBalanced();

        $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'delivery/withdrawals'))->assertForbidden();
    });

    it('lets the carrier review its own drivers, sets the payment off against the carrier, and keeps admin read only', function () {
        $owner = User::factory()->create();
        $carrier = Carrier::query()->create(['code' => 'fast', 'name' => 'Fast Couriers', 'status' => 'active', 'owner_user_id' => $owner->id]);
        $owner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);
        $supervisor = User::factory()->create();
        $supervisor->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);
        $this->agent->forceFill(['carrier_id' => $carrier->id])->save();
        fundAgentWallet($this->agent);
        $this->withToken($this->token)->getJson($this->api.'me/wallet')->assertJsonPath('data.paid_by', 'carrier')->assertJsonPath('data.carrier', 'Fast Couriers');
        $withdrawal = app(AgentPay::class)->requestWithdrawal($this->agent->refresh(), app(MoneyFactory::class)->of('50', 'AED'), ['account_holder' => 'Rashid', 'bank_name' => 'ENBD', 'iban' => VALID_IBAN], $this->user->id);

        $this->actingAs(adminAccount('uae', 'finance'))->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->actingAs($owner)->get(onPanel('carrier', 'withdrawals'))->assertOk()->assertSee($withdrawal->number);
        $this->actingAs($owner)->post(onPanel('carrier', "withdrawals/{$withdrawal->id}"), ['action' => 'approve'])->assertRedirect();
        $this->actingAs($supervisor)->post(onPanel('carrier', "withdrawals/{$withdrawal->id}"), ['action' => 'pay', 'reference' => 'CR-9'])->assertRedirect()->assertSessionHasNoErrors();
        expect($withdrawal->refresh()->status)->toBe(AgentWithdrawalStatus::Paid)
            ->and(ledgerBalance(AccountRef::carrierReceivable($carrier->id)))->toBe('-50.00');
        assertLedgerBalanced();

        $rivalOwner = User::factory()->create();
        $rival = Carrier::query()->create(['code' => 'slow', 'name' => 'Slow', 'status' => 'active', 'owner_user_id' => $rivalOwner->id]);
        $rivalOwner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $rival->id]);
        $this->actingAs($rivalOwner)->post(onPanel('carrier', "withdrawals/{$withdrawal->id}"), ['action' => 'reject', 'reason' => 'x'])->assertNotFound();
    });

    it('gives the money back when a withdrawal is rejected with a reason', function () {
        fundAgentWallet($this->agent);
        $withdrawal = app(AgentPay::class)->requestWithdrawal($this->agent, app(MoneyFactory::class)->of('70', 'AED'), ['account_holder' => 'Rashid', 'bank_name' => 'ENBD', 'iban' => VALID_IBAN], $this->user->id);
        $admin = adminAccount('uae', 'finance');
        $this->actingAs($admin)->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'reject'])->assertSessionHasErrors('action');
        $this->actingAs($admin)->post(onPanel('admin', "delivery/withdrawals/{$withdrawal->id}"), ['action' => 'reject', 'reason' => 'IBAN name mismatch'])->assertRedirect();

        expect($withdrawal->refresh()->status)->toBe(AgentWithdrawalStatus::Rejected)->and($withdrawal->reason)->toBe('IBAN name mismatch')
            ->and(ledgerBalance(AccountRef::agentWallet($this->user->id)))->toBe('100.00');
    });

    it('checks IBANs with the mod-97 rule', function () {
        expect(AgentPay::validIban(VALID_IBAN))->toBeTrue()
            ->and(AgentPay::validIban('GB82 WEST 1234 5698 7654 32'))->toBeTrue()
            ->and(AgentPay::validIban('AE070331234567890123457'))->toBeFalse()
            ->and(AgentPay::validIban('12345'))->toBeFalse();
    });
});

describe('parcel barcode', function () {
    it('encodes the shipment number with a Code 39 mod-43 check character', function () {
        expect(ParcelBarcode::checkChar('SH-NW-AE-1000123-1'))->toBe('I')
            ->and(ParcelBarcode::payload('SH-NW-AE-1000123-1'))->toBe('SH-NW-AE-1000123-1.I')
            ->and(ParcelBarcode::checkChar('A'))->toBe('A')
            ->and(ParcelBarcode::checkChar('a'))->toBeNull()
            ->and(ParcelBarcode::parse('*sh-nw-ae-1000123-1.i*'))->toBe(['base' => 'SH-NW-AE-1000123-1', 'labelled' => true, 'valid' => true])
            ->and(ParcelBarcode::parse('SH-NW-AE-1000123-1.J')['valid'])->toBeFalse()
            ->and(ParcelBarcode::parse('NW-AE-1000123-1'))->toBe(['base' => 'NW-AE-1000123-1', 'labelled' => false, 'valid' => true]);

        // A value whose check character is `.` (37): `X` (33) + `4` (4).
        expect(ParcelBarcode::payload('X4'))->toBe('X4..')->and(ParcelBarcode::parse('X4..'))->toBe(['base' => 'X4', 'labelled' => true, 'valid' => true]);
    });

    it('validates scans against the assignment and prints the label with the same payload', function () {
        $parcel = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
        $assignment = app(DeliveryDispatch::class)->assign($parcel->refresh(), $this->agent, ActorType::Admin, 1);
        $shipment = Shipment::query()->findOrFail($assignment->shipment_id);
        $payload = ParcelBarcode::payload($shipment);
        $other = app(DeliveryDispatch::class)->assign(subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed)->refresh(), $this->agent, ActorType::Admin, 1);

        $this->withToken($this->token)->getJson($this->api."assignments/{$assignment->number}")->assertOk()->assertJsonPath('data.barcode', $payload);
        $this->withToken($this->token)->postJson($this->api."assignments/{$assignment->number}/scan", ['code' => '*'.$payload.'*'])->assertOk()
            ->assertJsonPath('data.matches', true)->assertJsonPath('data.shipment_number', $shipment->number);
        $this->withToken($this->token)->postJson($this->api."assignments/{$assignment->number}/scan", ['code' => $parcel->number])->assertOk();
        $this->withToken($this->token)->postJson($this->api."assignments/{$assignment->number}/scan", ['code' => $shipment->number.'.Z'])->assertUnprocessable()->assertJsonPath('error.code', 'bad_label');
        $this->withToken($this->token)->postJson($this->api."assignments/{$assignment->number}/scan", ['code' => ParcelBarcode::payload(Shipment::query()->findOrFail($other->shipment_id))])
            ->assertUnprocessable()->assertJsonPath('error.code', 'wrong_parcel');

        $ops = adminAccount('uae', 'operations');
        $this->actingAs($ops)->get(onPanel('admin', "delivery/shipments/{$shipment->id}/label"))->assertOk()->assertSee('data-barcode="'.$payload.'"', false)->assertSee($shipment->number);
        $pdf = $this->actingAs($ops)->get(onPanel('admin', "delivery/shipments/{$shipment->id}/label.pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    });

    it('accepts the extra GPS fields from the background tracker', function () {
        $this->withToken($this->token)->postJson($this->api.'me/location', ['lat' => 25.2, 'lng' => 55.3, 'accuracy' => 8.5, 'heading' => 90, 'speed' => 11.2, 'recorded_at' => now()->toIso8601String()])->assertOk();
        $this->withToken($this->token)->postJson($this->api.'me/location', ['lat' => 25.2, 'lng' => 55.3, 'heading' => 400])->assertUnprocessable();
        expect((float) $this->agent->refresh()->last_lat)->toBe(25.2);
    });
});
