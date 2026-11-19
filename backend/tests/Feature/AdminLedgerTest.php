<?php

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Finance\Models\LedgerTransaction;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Identity\Enums\AdminPermission;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    [, $this->vendor] = sellerAccount();
    $this->actAsCountry('uae');
    $ledger = app(Ledger::class);

    $this->travelTo('2026-11-10 08:00:00');
    $this->sale = $ledger->post('ledger-test-sale', 'order_payment', (new Posting)
        ->debit(AccountRef::platform(LedgerAccountCode::GatewayClearing), Money::of('100.00', 'AED'))
        ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('100.00', 'AED')), reference: $this->vendor, description: '=HYPERLINK("x")');

    $this->travelTo('2026-11-15 08:00:00');
    $this->settlement = $ledger->post('ledger-test-settle', 'settlement', (new Posting)
        ->debit(AccountRef::platform(LedgerAccountCode::OrderLiability), Money::of('60.00', 'AED'))
        ->credit(AccountRef::vendor($this->vendor->id), Money::of('50.00', 'AED'))
        ->credit(AccountRef::platform(LedgerAccountCode::CommissionRevenue), Money::of('10.00', 'AED')));
    $this->travelBack();
});

it('is open to finance and super admins only, and shows the menu item to them', function () {
    expect(AdminPermission::defaultRoles()['finance'])->toContain(AdminPermission::LedgerView);

    foreach (['operations', 'marketing', 'support', 'account_manager'] as $role) {
        $this->actingAs(adminAccount('uae', $role));
        $this->get(onPanel('admin', 'finance/ledger'))->assertForbidden();
        $this->get(onPanel('admin', 'finance/ledger/entries'))->assertForbidden();
        $this->get(onPanel('admin', "finance/ledger/entries/{$this->sale->id}"))->assertForbidden();
        $this->get(onPanel('admin', 'finance/ledger/entries/export'))->assertForbidden();
    }

    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'finance/ledger'))->assertOk()
        ->assertSee(route('admin.finance.ledger'), false);
    $this->actingAs(adminAccount())->get(onPanel('admin', 'finance/ledger'))->assertOk();
    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'returns'))->assertOk()
        ->assertDontSee(route('admin.finance.ledger'), false);
});

it('lists the accounts of the country with their balances per currency', function () {
    $this->actingAs(adminAccount('uae', 'finance'));
    $vendorAccount = LedgerAccount::query()->where('owner_type', LedgerAccount::OWNER_VENDOR)->sole();

    $this->get(onPanel('admin', 'finance/ledger'))->assertOk()
        ->assertSee('data-ledger-total="order_liability:AED"', false)
        ->assertSee('AED 40.00')
        ->assertSee('data-ledger-account="'.$vendorAccount->id.'"', false)
        ->assertSee($this->vendor->store_name);

    $this->get(onPanel('admin', 'finance/ledger?owner_type=vendor'))->assertOk()
        ->assertSee('data-ledger-account="'.$vendorAccount->id.'"', false)
        ->assertDontSee('data-ledger-account="'.LedgerAccount::query()->where('code', 'gateway_clearing')->value('id').'"', false);

    // Another country has its own ledger.
    $this->actingAs(adminAccount('omn', 'finance'))->get(onPanel('admin', 'finance/ledger?country=omn'))->assertOk()->assertDontSee('data-ledger-account=', false);
});

it('filters journal entries by date range, account and reference type', function () {
    $this->actingAs(adminAccount('uae', 'finance'));
    $vendorAccount = LedgerAccount::query()->where('owner_type', LedgerAccount::OWNER_VENDOR)->sole();
    $sale = 'data-ledger-entry="'.$this->sale->id.'"';
    $settlement = 'data-ledger-entry="'.$this->settlement->id.'"';

    $this->get(onPanel('admin', 'finance/ledger/entries'))->assertOk()->assertSee($sale, false)->assertSee($settlement, false);
    $this->get(onPanel('admin', 'finance/ledger/entries?from=2026-11-12&to=2026-11-20'))->assertOk()->assertDontSee($sale, false)->assertSee($settlement, false);
    $this->get(onPanel('admin', 'finance/ledger/entries?to=2026-11-10'))->assertOk()->assertSee($sale, false)->assertDontSee($settlement, false);
    $this->get(onPanel('admin', 'finance/ledger/entries?account='.$vendorAccount->id))->assertOk()->assertDontSee($sale, false)->assertSee($settlement, false);
    $this->get(onPanel('admin', 'finance/ledger/entries?reference_type=vendor'))->assertOk()->assertSee($sale, false)->assertDontSee($settlement, false);
    $this->get(onPanel('admin', 'finance/ledger/entries?from=2026-11-20&to=2026-11-01'))->assertSessionHasErrors('to');
});

it('shows an entry with its debit and credit lines', function () {
    $this->actingAs(adminAccount('uae', 'finance'));

    $response = $this->get(onPanel('admin', "finance/ledger/entries/{$this->settlement->id}"))->assertOk()
        ->assertSee('JE-'.$this->settlement->id)
        ->assertSee('ledger-test-settle');

    foreach ($this->settlement->entries as $line) {
        $response->assertSee('data-ledger-line="'.$line->id.'" data-direction="'.$line->direction.'"', false);
    }
    expect($this->settlement->entries)->toHaveCount(3);
    $response->assertSeeInOrder(['data-ledger-totals', 'AED 60.00', 'AED 60.00'], false);

    $this->get(onPanel('admin', 'finance/ledger/entries/999999'))->assertNotFound();
});

it('exports the filtered journal entries as CSV, one row per line', function () {
    $this->actingAs(adminAccount('uae', 'finance'));

    $csv = $this->get(onPanel('admin', 'finance/ledger/entries/export?reference_type=vendor'))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')->streamedContent();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));

    expect($rows[0])->toContain('entry', 'account_code', 'debit', 'credit')
        ->and($rows)->toHaveCount(3)
        ->and(array_unique(array_column(array_slice($rows, 1), 0)))->toBe([(string) $this->sale->id])
        ->and(array_column(array_slice($rows, 1), 8))->toBe(['gateway_clearing', 'order_liability'])
        // Free text cannot run as a spreadsheet formula.
        ->and($rows[1][5])->toStartWith("'=");

    $all = $this->get(onPanel('admin', 'finance/ledger/entries/export'))->assertOk()->streamedContent();
    expect(substr_count(trim($all), "\n"))->toBe(LedgerTransaction::query()->withCount('entries')->get()->sum('entries_count'));
});
