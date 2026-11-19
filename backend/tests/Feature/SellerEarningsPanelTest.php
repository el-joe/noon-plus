<?php

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->travelTo('2026-11-18 10:00:00');
    $this->country = $this->actAsCountry('omn');
    Notification::fake();
});

/**
 * A settlement of a new sub-order of the vendor, settled at `$settledAt` (UTC).
 *
 * @param  array<string, string>  $amounts
 */
function earningsSettlement(Vendor $vendor, string $settledAt, array $amounts): SubOrderSettlement
{
    $subOrder = subOrderFor($vendor, SubOrderStatus::Placed);
    $columns = ['gross', 'vendor_discount', 'refunds', 'commission', 'commission_vat', 'fees', 'fbn_fees', 'subsidy_share', 'campaign_fees', 'sample_commissions'];
    $values = array_merge(array_fill_keys($columns, '0'), $amounts);
    $net = $values['gross'];

    foreach (array_slice($columns, 1) as $column) {
        $net = bcsub($net, $values[$column], 4);
    }

    return SubOrderSettlement::query()->create($values + [
        'sub_order_id' => $subOrder->id, 'vendor_id' => $vendor->id, 'currency' => app(CountryContext::class)->current()->currency,
        'is_cod' => false, 'net' => $net, 'lines' => [], 'settled_at' => $settledAt,
    ]);
}

function earningsItem(Vendor $vendor, string $type, string $amount, string $createdAt, int $reference): void
{
    $item = VendorBalanceItem::query()->create(['vendor_id' => $vendor->id, 'type' => $type, 'reference_type' => 'test', 'reference_id' => $reference, 'description' => $type, 'amount' => $amount, 'is_eligible' => true]);
    $item->forceFill(['created_at' => $createdAt])->save();
}

function earningsStaff(Vendor $vendor, ?string $tenantRole): User
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => MembershipRole::ProductVendor, 'country_id' => test()->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $vendor->id, 'tenant_role' => $tenantRole]);

    return $user;
}

/**
 * The Oman store's November and October activity (3-decimal OMR).
 */
function omanEarningsFixture(Vendor $vendor): void
{
    // 21:00 UTC on 31 Oct is 01:00 on 1 Nov in Muscat → November.
    earningsSettlement($vendor, '2026-10-31 21:00:00', ['gross' => '100.500', 'vendor_discount' => '5.250', 'commission' => '10.050', 'commission_vat' => '0.503', 'fees' => '1.000', 'fbn_fees' => '0.250']);
    earningsSettlement($vendor, '2026-10-15 08:00:00', ['gross' => '40.000', 'refunds' => '10.000', 'commission' => '4.000', 'commission_vat' => '0.200', 'fees' => '0.500', 'subsidy_share' => '0.125']);
    earningsSettlement($vendor, '2025-01-10 08:00:00', ['gross' => '999.000']);
    earningsItem($vendor, VendorBalanceItem::TYPE_CLAWBACK, '-2.500', '2026-11-05 10:00:00', 1);
    earningsItem($vendor, VendorBalanceItem::TYPE_MARKETER_COMMISSION, '-3.333', '2026-10-20 10:00:00', 2);
    earningsItem($vendor, VendorBalanceItem::TYPE_AD_BOOKING_REFUND, '1.000', '2026-10-21 10:00:00', 3);
    earningsItem($vendor, VendorBalanceItem::TYPE_SETTLEMENT, '83.447', '2026-11-01 10:00:00', 4);
}

it('sums the ledger buckets, payouts and the period by Muscat month in 3-decimal OMR', function () {
    [$owner, $vendor] = sellerAccount('omn');
    omanEarningsFixture($vendor);
    $money = app(MoneyFactory::class);
    app(Ledger::class)->post('test-earnings', 'test', (new Posting)
        ->debit(AccountRef::platform(LedgerAccountCode::GatewayClearing), $money->of('50.125', 'OMR'))
        ->credit(AccountRef::vendor($vendor->id, WalletBucket::Pending), $money->of('30.000', 'OMR'))
        ->credit(AccountRef::vendor($vendor->id, WalletBucket::Spendable), $money->of('20.125', 'OMR')));
    VendorPayout::query()->create(['number' => 'ST-1', 'vendor_id' => $vendor->id, 'currency' => 'OMR', 'status' => PayoutStatus::Completed, 'schedule' => 'weekly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-07', 'total' => '12.345']);
    VendorPayout::query()->create(['number' => 'ST-2', 'vendor_id' => $vendor->id, 'currency' => 'OMR', 'status' => PayoutStatus::Pending, 'schedule' => 'weekly', 'period_start' => '2026-10-08', 'period_end' => '2026-10-14', 'total' => '99.000']);

    $response = $this->actingAs($owner)->get(onPanel('partner', 'earnings'))->assertOk()
        ->assertSee(route('partner.statements'), false)->assertSee(route('partner.payouts'), false);

    $amount = fn ($money): string => (string) $money->getAmount();
    $balances = $response->viewData('balances');
    expect($amount($balances['pending']))->toBe('30.000')
        ->and($amount($balances['spendable']))->toBe('20.125')
        ->and($amount($balances['reserved']))->toBe('0.000')
        ->and($amount($balances['paid_out']))->toBe('12.345')
        ->and($balances['paid_out_count'])->toBe(1);

    $report = $response->viewData('report');
    $totals = $report['totals'];
    expect($totals['orders'])->toBe(2)
        ->and(array_map($amount, array_intersect_key($totals, array_flip(['gross', 'coupons', 'refunds', 'commission', 'fees', 'marketing', 'net']))))->toBe([
            'gross' => '140.500', 'coupons' => '5.250', 'refunds' => '12.500', 'commission' => '14.753', 'fees' => '1.875', 'marketing' => '2.333', 'net' => '103.789',
        ])
        ->and($report['months'])->toHaveCount(12)
        ->and($report['months'][0]['month'])->toBe('2026-11')
        ->and($amount($report['months'][0]['net']))->toBe('80.947')
        ->and($amount($report['months'][0]['refunds']))->toBe('2.500')
        ->and($report['months'][1]['month'])->toBe('2026-10')
        ->and($amount($report['months'][1]['net']))->toBe('22.842')
        ->and($amount($report['months'][1]['marketing']))->toBe('2.333')
        ->and($report['months'][11]['month'])->toBe('2025-12');
});

it('filters by a chosen period on Muscat days and validates the dates', function () {
    [$owner, $vendor] = sellerAccount('omn');
    omanEarningsFixture($vendor);
    $gross = fn (string $query): string => (string) $this->actingAs($owner)->get(onPanel('partner', 'earnings?'.$query))->assertOk()->viewData('report')['totals']['gross']->getAmount();

    expect($gross('period=custom&from=2026-11-01&to=2026-11-30'))->toBe('100.500')
        ->and($gross('period=custom&from=2026-10-01&to=2026-10-31'))->toBe('40.000')
        ->and($gross('period=this_month'))->toBe('100.500')
        ->and($gross('period=last_month'))->toBe('40.000')
        ->and($gross('period=this_year'))->toBe('140.500')
        ->and($gross('period=custom&from=2025-01-01&to=2025-01-31'))->toBe('999.000');

    $this->actingAs($owner)->get(onPanel('partner', 'earnings?period=custom&from=2026-11-10&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($owner)->get(onPanel('partner', 'earnings?period=custom&from=2020-01-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($owner)->get(onPanel('partner', 'earnings?period=custom'))->assertSessionHasErrors(['from', 'to']);
    $this->actingAs($owner)->get(onPanel('partner', 'earnings?period=forever'))->assertSessionHasErrors('period');
});

it('is hidden from staff without the finance group', function () {
    [, $vendor] = sellerAccount('omn');

    foreach (['finance', 'manager'] as $role) {
        $this->actingAs(earningsStaff($vendor, $role))->get(onPanel('partner', 'earnings'))->assertOk();
    }

    foreach (['catalog', 'orders', 'marketing', null] as $role) {
        $this->actingAs(earningsStaff($vendor, $role))->get(onPanel('partner', 'earnings'))->assertForbidden();
    }
});

it('reads only the store\'s own country database and currency', function () {
    [$omanOwner, $omanVendor] = sellerAccount('omn');
    earningsSettlement($omanVendor, '2026-11-02 08:00:00', ['gross' => '10.000']);
    $rivalInOman = Vendor::factory()->create();
    earningsSettlement($rivalInOman, '2026-11-02 08:00:00', ['gross' => '77.000']);

    $this->actAsCountry('uae');
    [$uaeOwner, $uaeVendor] = sellerAccount('uae');
    earningsSettlement($uaeVendor, '2026-11-02 08:00:00', ['gross' => '500.00']);

    $uae = $this->actingAs($uaeOwner)->get(onPanel('partner', 'earnings'))->assertOk()->viewData('report')['totals']['gross'];
    expect((string) $uae->getAmount())->toBe('500.00')->and($uae->getCurrency()->getCurrencyCode())->toBe('AED');

    app('auth')->forgetGuards();
    $oman = $this->actingAs($omanOwner)->get(onPanel('partner', 'earnings'))->assertOk()->viewData('report')['totals']['gross'];
    expect((string) $oman->getAmount())->toBe('10.000')->and($oman->getCurrency()->getCurrencyCode())->toBe('OMR');
});
