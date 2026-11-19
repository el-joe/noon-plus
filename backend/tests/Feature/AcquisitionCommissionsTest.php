<?php

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\AcquisitionCommissionStatus;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\AcquisitionCommission;
use App\Domains\Finance\Models\AcquisitionCommissionRule;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    $this->withoutVite();
    Cache::flush();
});

/**
 * A settled sub-order of the vendor (gross − coupon − refunds = sales) at `$settledAt` (UTC).
 */
function acquisitionSettlement(Vendor $vendor, string $settledAt, string $gross, string $discount = '0', string $refunds = '0'): SubOrderSettlement
{
    $subOrder = subOrderFor($vendor, SubOrderStatus::Placed);

    return SubOrderSettlement::query()->create([
        'sub_order_id' => $subOrder->id, 'vendor_id' => $vendor->id, 'currency' => app(CountryContext::class)->current()->currency, 'is_cod' => false,
        'gross' => $gross, 'vendor_discount' => $discount, 'refunds' => $refunds, 'net' => bcsub(bcsub($gross, $discount, 4), $refunds, 4), 'lines' => [], 'settled_at' => $settledAt,
    ]);
}

function acquisitionRule(array $attributes = []): AcquisitionCommissionRule
{
    return AcquisitionCommissionRule::query()->create($attributes + [
        'name' => 'Standard', 'min_monthly_sales' => '1000', 'max_monthly_sales' => '50000', 'rate_percent' => '2.5', 'starts_on' => '2026-01-01', 'is_active' => true,
    ]);
}

it('computes the previous month per vendor inside the sales band, accrues it to the acquirer and is idempotent', function () {
    $acquirer = adminAccount('uae', 'account_manager');
    $vendor = Vendor::factory()->create(['acquired_by_user_id' => $acquirer->id, 'acquired_at' => '2026-09-01']);
    $small = Vendor::factory()->create(['acquired_by_user_id' => $acquirer->id]);
    $unattributed = Vendor::factory()->create();
    acquisitionRule();
    acquisitionRule(['name' => 'Ended', 'rate_percent' => '9', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

    // October 2026 in Dubai time: 1 Oct 00:00 +04 = 30 Sep 20:00 UTC.
    acquisitionSettlement($vendor, '2026-09-30 21:00:00', '1500.00', '100.00', '200.00');
    acquisitionSettlement($vendor, '2026-10-20 10:00:00', '800.00');
    acquisitionSettlement($vendor, '2026-09-30 19:00:00', '9999.00'); // still September locally
    acquisitionSettlement($small, '2026-10-05 10:00:00', '400.00');   // below the band
    acquisitionSettlement($unattributed, '2026-10-05 10:00:00', '5000.00');

    $this->artisan('nawy:finance:acquisition-commissions', ['--month' => '2026-10-10'])->assertSuccessful();
    $this->artisan('nawy:finance:acquisition-commissions', ['--month' => '2026-10-10'])->assertSuccessful();

    $commission = AcquisitionCommission::query()->sole();
    expect($commission->vendor_id)->toBe($vendor->id)
        ->and($commission->status)->toBe(AcquisitionCommissionStatus::Pending)
        ->and($commission->period_start->toDateString())->toBe('2026-10-01')
        ->and((string) $commission->sales_amount)->toBe('2000.0000')
        ->and($commission->sub_orders_count)->toBe(2)
        ->and((string) $commission->amount)->toBe('50.0000')
        ->and($commission->number)->toStartWith('ACQ-AE-')
        ->and($commission->transitions()->count())->toBe(1)
        ->and(ledgerBalance(AccountRef::staff($acquirer->id)))->toBe('50.00')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AcquisitionCommissionExpense)))->toBe('50.00');
    assertLedgerBalanced();
});

it('uses the acquirer\'s own rule before the general one and the highest matching band', function () {
    $acquirer = adminAccount('uae', 'account_manager');
    $vendor = Vendor::factory()->create(['acquired_by_user_id' => $acquirer->id]);
    acquisitionRule(['name' => 'General', 'rate_percent' => '1']);
    acquisitionRule(['name' => 'Personal low', 'acquirer_user_id' => $acquirer->id, 'min_monthly_sales' => '0', 'max_monthly_sales' => '5000', 'rate_percent' => '3']);
    acquisitionRule(['name' => 'Personal high', 'acquirer_user_id' => $acquirer->id, 'min_monthly_sales' => '2000', 'max_monthly_sales' => null, 'rate_percent' => '4']);
    acquisitionSettlement($vendor, '2026-10-05 10:00:00', '3000.00');

    $this->artisan('nawy:finance:acquisition-commissions', ['--month' => '2026-10-10'])->assertSuccessful();

    expect(AcquisitionCommission::query()->sole())->rule->name->toBe('Personal high')
        ->and((string) AcquisitionCommission::query()->sole()->amount)->toBe('120.0000');
});

it('lets finance approve and pay a commission by bank transfer, never the acquirer, and reverses a rejected one', function () {
    $acquirer = adminAccount('uae', 'account_manager');
    $finance = adminAccount('uae', 'finance');
    $vendor = Vendor::factory()->create(['acquired_by_user_id' => $finance->id]);
    $other = Vendor::factory()->create(['acquired_by_user_id' => $acquirer->id]);
    acquisitionRule();
    acquisitionSettlement($vendor, '2026-10-05 10:00:00', '2000.00');
    acquisitionSettlement($other, '2026-10-05 10:00:00', '4000.00');
    $this->artisan('nawy:finance:acquisition-commissions', ['--month' => '2026-10-10'])->assertSuccessful();
    $own = AcquisitionCommission::query()->where('vendor_id', $vendor->id)->sole();
    $theirs = AcquisitionCommission::query()->where('vendor_id', $other->id)->sole();

    $this->actingAs($finance)->post(onPanel('admin', "finance/acquisition-commissions/{$own->id}/approve"))->assertSessionHasErrors('action');
    expect($own->refresh()->status)->toBe(AcquisitionCommissionStatus::Pending);

    $this->actingAs($finance)->post(onPanel('admin', "finance/acquisition-commissions/{$theirs->id}/approve"))->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(onPanel('admin', "finance/acquisition-commissions/{$theirs->id}/pay"), ['bank_reference' => 'TRX-77'])->assertSessionHasNoErrors();
    expect($theirs->refresh()->status)->toBe(AcquisitionCommissionStatus::Paid)
        ->and($theirs->bank_reference)->toBe('TRX-77')
        ->and(ledgerBalance(AccountRef::staff($acquirer->id)))->toBe('0.00')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('-100.00');

    $this->actingAs($finance)->post(onPanel('admin', "finance/acquisition-commissions/{$own->id}/reject"), ['reason' => 'Duplicate store'])->assertSessionHasNoErrors();
    expect($own->refresh()->status)->toBe(AcquisitionCommissionStatus::Rejected)
        ->and(ledgerBalance(AccountRef::staff($finance->id)))->toBe('0.00')
        ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::AcquisitionCommissionExpense)))->toBe('100.00');

    $this->actingAs($finance)->get(onPanel('admin', 'finance/acquisition-commissions'))->assertOk()->assertSee($theirs->number)->assertSee($own->number);
    $this->actingAs($finance)->get(onPanel('admin', "finance/acquisition-commissions/{$theirs->id}"))->assertOk()->assertSee('TRX-77');
    $this->actingAs($acquirer)->get(onPanel('admin', 'finance/acquisition-commissions'))->assertForbidden();
    assertLedgerBalanced();
});

it('shows each admin only their own commissions under My acquisition commissions', function () {
    $acquirer = adminAccount('uae', 'account_manager');
    $colleague = adminAccount('uae', 'account_manager');
    acquisitionRule();
    $mine = Vendor::factory()->create(['acquired_by_user_id' => $acquirer->id, 'store_name' => 'Mine Store']);
    $theirs = Vendor::factory()->create(['acquired_by_user_id' => $colleague->id, 'store_name' => 'Their Store']);
    acquisitionSettlement($mine, '2026-10-05 10:00:00', '2000.00');
    acquisitionSettlement($theirs, '2026-10-05 10:00:00', '2000.00');
    $this->artisan('nawy:finance:acquisition-commissions', ['--month' => '2026-10-10'])->assertSuccessful();

    $this->actingAs($acquirer)->get(onPanel('admin', 'my-acquisition-commissions'))->assertOk()
        ->assertSee('Mine Store')->assertDontSee('Their Store');
});

it('lets finance manage rules and vendor relations credit a vendor\'s acquisition (defaulting to the first account manager)', function () {
    $finance = adminAccount('uae', 'finance');
    $relations = adminAccount('uae', 'vendor_relations');
    $manager = adminAccount('uae', 'account_manager');

    $this->actingAs($finance)->post(onPanel('admin', 'finance/acquisition-rules'), [
        'name' => 'Launch band', 'min_monthly_sales' => '500', 'max_monthly_sales' => '100', 'rate_percent' => '2', 'starts_on' => '2026-11-01',
    ])->assertSessionHasErrors('max_monthly_sales');
    $this->actingAs($finance)->post(onPanel('admin', 'finance/acquisition-rules'), [
        'name' => 'Launch band', 'acquirer_user_id' => $manager->id, 'min_monthly_sales' => '500', 'max_monthly_sales' => '', 'rate_percent' => '2', 'starts_on' => '2026-11-01', 'is_active' => 1,
    ])->assertSessionHasNoErrors();
    $rule = AcquisitionCommissionRule::query()->sole();
    expect($rule->acquirer_user_id)->toBe($manager->id)->and($rule->max_monthly_sales)->toBeNull();
    $this->actingAs($finance)->put(onPanel('admin', "finance/acquisition-rules/{$rule->id}"), [
        'name' => 'Launch band', 'min_monthly_sales' => '500', 'rate_percent' => '3', 'starts_on' => '2026-11-01', 'is_active' => 1,
    ])->assertRedirect();
    expect((string) $rule->refresh()->rate_percent)->toBe('3.0000')->and($rule->acquirer_user_id)->toBeNull();
    $this->actingAs($finance)->get(onPanel('admin', 'finance/acquisition-rules'))->assertOk()->assertSee('Launch band');
    // Browser test BT-ADM-003: the edit form shows "500", not the stored "500.0000" that its own decimal:0,3 rule refuses.
    $this->actingAs($finance)->get(onPanel('admin', "finance/acquisition-rules/{$rule->id}/edit"))->assertOk()
        ->assertSee('value="500"', false)->assertDontSee('500.0000');

    $vendor = Vendor::factory()->create();
    $this->actingAs($relations)->post(onPanel('admin', "vendors/{$vendor->id}/account-manager"), ['account_manager_id' => $manager->id])->assertRedirect();
    expect($vendor->refresh()->acquired_by_user_id)->toBe($manager->id)->and($vendor->acquired_at)->not->toBeNull();

    $this->actingAs($relations)->post(onPanel('admin', "vendors/{$vendor->id}/acquirer"), ['acquired_by_user_id' => $relations->id, 'acquired_at' => '2026-08-01'])->assertSessionHasNoErrors();
    expect($vendor->refresh()->acquired_by_user_id)->toBe($relations->id)->and($vendor->acquired_at->toDateString())->toBe('2026-08-01');
    $this->actingAs($relations)->get(onPanel('admin', "vendors/{$vendor->id}"))->assertOk()->assertSee($relations->name);
    $this->actingAs($relations)->post(onPanel('admin', "vendors/{$vendor->id}/acquirer"), ['acquired_by_user_id' => User::factory()->create()->id])->assertSessionHasErrors('acquired_by_user_id');
});
