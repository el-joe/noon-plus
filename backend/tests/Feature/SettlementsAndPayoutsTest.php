<?php

use App\Domains\Finance\Enums\CodSettlementStatus;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\CodSettlement;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\CodSettlements;
use App\Domains\Finance\Support\Payouts;
use App\Domains\Finance\Support\Settlements;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Support\Refunds;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
});

/**
 * A completed card sub-order of `$quantity` × `$price` with a commission rate.
 */
function completedCardSubOrder(Vendor $vendor, string $price = '100.00', int $quantity = 2, string $rate = '10.00', string $market = 'uae-en'): SubOrder
{
    [$order] = placeOrderVia('card', $price, $market, $quantity, vendor: $vendor);
    captureOrderPayment($order);
    $subOrder = $order->subOrders()->first();
    $subOrder->items()->update(['commission_rate' => $rate]);

    return moveSubOrderTo($subOrder, SubOrderStatus::Completed);
}

describe('settlement on completion', function () {
    it('snapshots the category commission rate on the order item at placement', function () {
        [$order] = placeOrderVia('cod');

        expect($order->subOrders()->first()->items()->first()->commission_rate)->toBe('10.00');
    });

    it('settles net = price − commission − VAT on commission − fees − subsidy share and balances the ledger', function () {
        app(SettingsService::class)->set('payout.fixed_fee_per_sub_order', '2.00', $this->country);
        $vendor = Vendor::factory()->create();

        $subOrder = completedCardSubOrder($vendor, '100.00', 2, '12.50');
        $settlement = SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->firstOrFail();

        expect($settlement)
            ->gross->toBe('200.0000')
            ->commission->toBe('25.0000')
            ->commission_vat->toBe('1.2500')
            ->fees->toBe('2.0000')
            ->net->toBe('171.7500')
            ->and($settlement->lines[0]['commission_rate'])->toBe('12.50')
            ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('171.75')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::CommissionRevenue)))->toBe('25.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::OrderLiability)))->toBe('0.00');
        assertLedgerBalanced();
    });

    it('rounds a 3-decimal currency half-up', function () {
        $this->actAsCountry('omn');
        $vendor = Vendor::factory()->create();

        $subOrder = completedCardSubOrder($vendor, '10.555', 1, '10.00', 'omn-en');

        expect(SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->firstOrFail())
            ->commission->toBe('1.0560')
            ->commission_vat->toBe('0.0530')
            ->net->toBe('9.4460');
        assertLedgerBalanced();
    });

    it('deducts the vendor shipping-subsidy share and refunds made before completion', function () {
        $vendor = Vendor::factory()->create();
        [$order] = placeOrderVia('stripe', '100.00', quantity: 2, vendor: $vendor);
        captureOrderPayment($order);
        $subOrder = $order->subOrders()->first();
        $subOrder->forceFill(['shipping_subsidy_vendor' => '4.00'])->save();
        $subOrder->items()->update(['commission_rate' => '10.00']);
        moveSubOrderTo($subOrder, SubOrderStatus::Delivered);
        app(Refunds::class)->issue($order, $subOrder, Money::of('100.00', 'AED'), RefundSource::Admin, null,
            RefundDestination::Original, 'pre-settlement', ActorType::Admin, 1);

        moveSubOrderTo($subOrder, SubOrderStatus::Completed);

        expect(SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->firstOrFail())
            ->refunds->toBe('100.0000')
            ->commission->toBe('10.0000')
            ->subsidy_share->toBe('4.0000')
            ->net->toBe('85.5000');
        assertLedgerBalanced();
    });

    it('settles once even if completion is replayed', function () {
        $vendor = Vendor::factory()->create();
        $subOrder = completedCardSubOrder($vendor);

        app(Settlements::class)->settle($subOrder);

        expect(SubOrderSettlement::query()->count())->toBe(1)
            ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('179.00');
    });
});

describe('payout statements', function () {
    it('generates on each vendor schedule and reserves the balance', function () {
        $weekly = Vendor::factory()->create(['payout_schedule' => 'weekly']);
        $monthly = Vendor::factory()->create(['payout_schedule' => 'monthly']);
        completedCardSubOrder($weekly);
        completedCardSubOrder($monthly);

        $this->travelTo(CarbonImmutable::parse('2026-11-16 02:00', 'Asia/Dubai'));
        $this->artisan('nawy:payouts:generate')->assertSuccessful();
        $this->artisan('nawy:payouts:generate')->assertSuccessful();

        $payout = VendorPayout::query()->sole();
        expect($payout)->vendor_id->toBe($weekly->id)->total->toBe('179.0000')->status->toBe(PayoutStatus::Pending)
            ->and(ledgerBalance(AccountRef::vendor($weekly->id)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::vendor($weekly->id, WalletBucket::Reserved)))->toBe('179.00');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Asia/Dubai'));
        $this->artisan('nawy:payouts:generate')->assertSuccessful();
        expect(VendorPayout::query()->where('vendor_id', $monthly->id)->count())->toBe(1);
    });

    it('skips totals under the minimum and clawbacks alone', function () {
        app(SettingsService::class)->set('payout.minimum_amount', '500.00', $this->country);
        $vendor = Vendor::factory()->create();
        completedCardSubOrder($vendor);

        expect(app(Payouts::class)->generate($this->country, true))->toBe(0);
    });

    it('runs approve → process → completed with four eyes and books the bank transfer', function () {
        $vendor = Vendor::factory()->create();
        completedCardSubOrder($vendor);
        $maker = adminAccount('uae', 'finance');
        $checker = adminAccount('uae', 'finance');

        $this->actingAs($maker)->post(onPanel('admin', 'finance/payouts/generate'))->assertRedirect();
        $payout = VendorPayout::query()->sole();
        $this->post(onPanel('admin', "finance/payouts/{$payout->id}/approve"))->assertSessionHasErrors('action');
        expect($payout->refresh()->status)->toBe(PayoutStatus::Pending);

        $this->actingAs($checker)->post(onPanel('admin', "finance/payouts/{$payout->id}/approve"))->assertSessionHasNoErrors();
        $this->post(onPanel('admin', "finance/payouts/{$payout->id}/process"));
        $this->post(onPanel('admin', "finance/payouts/{$payout->id}/fail"), ['reason' => 'IBAN rejected']);
        $this->post(onPanel('admin', "finance/payouts/{$payout->id}/process"));
        $this->post(onPanel('admin', "finance/payouts/{$payout->id}/complete"), ['bank_reference' => 'NWY88120'])->assertSessionHasNoErrors();

        expect($payout->refresh())->status->toBe(PayoutStatus::Completed)->bank_reference->toBe('NWY88120')
            ->and(ledgerBalance(AccountRef::vendor($vendor->id, WalletBucket::Reserved)))->toBe('0.00')
            ->and(ledgerBalance(AccountRef::platform(LedgerAccountCode::BankClearing)))->toBe('-179.00')
            ->and($payout->transitions()->pluck('to_status')->all())->toBe(['pending', 'approved', 'processing', 'failed', 'processing', 'completed']);
        assertLedgerBalanced();
    });

    it('holds, releases and recalculates a pending statement', function () {
        $vendor = Vendor::factory()->create();
        completedCardSubOrder($vendor);
        app(Payouts::class)->generate($this->country, true);
        $payout = VendorPayout::query()->sole();
        completedCardSubOrder($vendor, '50.00', 1);

        app(Payouts::class)->hold($payout, 9, 'KYC document expired');
        expect(fn () => app(Payouts::class)->approve($payout->refresh(), 9))->toThrow(TransitionRefused::class);
        app(Payouts::class)->release($payout->refresh(), 9);
        app(Payouts::class)->recalculate($payout->refresh(), 9);

        expect($payout->refresh()->total)->toBe('223.7500')
            ->and(ledgerBalance(AccountRef::vendor($vendor->id, WalletBucket::Reserved)))->toBe('223.75');
        assertLedgerBalanced();
    });

    it('limits payout pages to finance and the store, per country', function () {
        [$sellerUser, $vendor] = sellerAccount();
        $this->actAsCountry('uae');
        completedCardSubOrder($vendor);
        app(Payouts::class)->generate($this->country, true);
        $payout = VendorPayout::query()->sole();
        [$otherSeller] = sellerAccount();
        app('auth')->forgetGuards();

        $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'finance/payouts'))->assertForbidden();
        $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'finance/payouts'))->assertOk()->assertSee($payout->number);
        $this->get(onPanel('admin', "finance/payouts/{$payout->id}"))->assertOk()->assertSee('Referral fee')->assertSee('AED 179.00');
        $this->actingAs(adminAccount('omn', 'finance'))->get(onPanel('admin', 'finance/payouts?country=omn'))->assertOk()->assertDontSee($payout->number);

        $this->actingAs($sellerUser)->get(onPanel('partner', 'payouts'))->assertOk()->assertSee($payout->number);
        $this->get(onPanel('partner', "payouts/{$payout->id}"))->assertOk()->assertSee('Net payout');
        $this->actingAs($otherSeller)->get(onPanel('partner', "payouts/{$payout->id}"))->assertNotFound();
    });
});

describe('cash on delivery', function () {
    it('collects on delivery, captures when nothing is left to deliver and holds the payout until remitted', function () {
        $vendor = Vendor::factory()->create();
        [$order] = placeOrderVia('cod', '100.00', vendor: $vendor);
        $subOrder = $order->subOrders()->first();
        $subOrder->items()->update(['commission_rate' => '10.00']);

        moveSubOrderTo($subOrder, SubOrderStatus::Delivered);
        $collection = CodCollection::query()->sole();
        expect($collection->amount)->toBe((string) $order->grand_total)
            ->and($order->payments()->first())->status->toBe(PaymentStatus::Captured)->captured_amount->toBe((string) $order->grand_total);

        moveSubOrderTo($subOrder, SubOrderStatus::Completed);
        expect(ledgerBalance(AccountRef::vendor($vendor->id, WalletBucket::Pending)))->toBe('89.50')
            ->and(app(Payouts::class)->generate($this->country, true))->toBe(0);

        $this->artisan('nawy:cod:generate-settlements')->assertSuccessful();
        $settlement = CodSettlement::query()->sole();
        expect($settlement->expected_amount)->toBe((string) $order->grand_total);

        $variance = app(CodSettlements::class)->settle($settlement, Money::of('50.00', 'AED'), 1);
        expect($variance->status)->toBe(CodSettlementStatus::Disputed)
            ->and($subOrder->refresh()->cod_remittance_confirmed)->toBeFalse();

        app(CodSettlements::class)->settle($settlement->refresh(), Money::of((string) $order->grand_total, 'AED'), 1);
        expect($settlement->refresh()->status)->toBe(CodSettlementStatus::Settled)
            ->and($subOrder->refresh()->cod_remittance_confirmed)->toBeTrue()
            ->and(ledgerBalance(AccountRef::vendor($vendor->id)))->toBe('89.50')
            ->and(ledgerBalance(AccountRef::codReceivable(null)))->toBe('0.00')
            ->and(app(Payouts::class)->generate($this->country, true))->toBe(1);
        assertLedgerBalanced();
    });

    it('settles from the admin page and refuses roles without the permission', function () {
        [$order] = placeOrderVia('cod', '100.00');
        moveSubOrderTo($order->subOrders()->first(), SubOrderStatus::Delivered);
        app(CodSettlements::class)->generate($this->country);
        $settlement = CodSettlement::query()->sole();

        $this->actingAs(adminAccount('uae', 'operations'))->post(onPanel('admin', "finance/cod-settlements/{$settlement->id}/settle"), ['remitted_amount' => '1'])->assertForbidden();
        $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'finance/cod-settlements'))->assertOk()->assertSee($settlement->number);
        $this->post(onPanel('admin', "finance/cod-settlements/{$settlement->id}/settle"), ['remitted_amount' => (string) Money::of((string) $order->grand_total, 'AED')->getAmount()])
            ->assertSessionHasNoErrors();

        expect($settlement->refresh()->status)->toBe(CodSettlementStatus::Settled);
    });
});
