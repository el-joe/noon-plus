<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Reporting\Models\EtlRun;
use App\Domains\Reporting\Support\ReportingEtl;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
});

/**
 * Orders of one country on a local day: two kept (one of them COD), one cancelled.
 */
function reportingOrders(string $code, CarbonImmutable $localNoon): void
{
    $country = Country::query()->where('code', $code)->sole();
    app(CountryContext::class)->runAs($country, function () use ($localNoon): void {
        $vendor = Vendor::factory()->create();
        subOrderFor($vendor, quantity: 2, placedAt: $localNoon->utc());
        subOrderFor($vendor, placedAt: $localNoon->utc());
        $cancelled = subOrderFor($vendor, SubOrderStatus::Cancelled, placedAt: $localNoon->utc());
        $cancelled->order->forceFill(['status' => 'cancelled'])->save();
    });
}

it('loads one local day per country into the reporting database, per currency, idempotently', function () {
    $day = CarbonImmutable::parse('2026-11-10');
    reportingOrders('uae', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Dubai'));
    reportingOrders('omn', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Muscat'));
    // 23:30 Dubai on the 9th is still the 9th locally (19:30 UTC) — not part of the 10th.
    app(CountryContext::class)->runAs(Country::query()->where('code', 'uae')->sole(), fn () => subOrderFor(Vendor::factory()->create(), placedAt: CarbonImmutable::parse('2026-11-09 23:30', 'Asia/Dubai')->utc()));

    app(CountryContext::class)->runAs(Country::query()->where('code', 'uae')->sole(), function (): void {
        app(Ledger::class)->post('test:revenue', 'test', (new Posting)
            ->debit(AccountRef::platform(LedgerAccountCode::BankClearing), app(MoneyFactory::class)->of('15.00', 'AED'))
            ->credit(AccountRef::platform(LedgerAccountCode::CommissionRevenue), app(MoneyFactory::class)->of('15.00', 'AED')));
        DB::connection('country_uae')->table('ledger_transactions')->where('idempotency_key', 'test:revenue')->update(['posted_at' => CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Dubai')->utc()]);
    });

    $this->artisan('nawy:reporting:etl', ['--date' => '2026-11-10'])->assertSuccessful();
    $this->artisan('nawy:reporting:etl', ['--date' => '2026-11-10'])->assertSuccessful();

    $sales = DB::connection('reporting')->table('fact_sales_daily')->where('report_date', $day->toDateString())->get()->keyBy('country_code');

    expect($sales)->toHaveCount(2)
        ->and($sales['uae']->currency)->toBe('AED')
        ->and((int) $sales['uae']->orders_count)->toBe(2)
        ->and((string) $sales['uae']->gmv)->toBe('300.0000')
        ->and((string) $sales['uae']->average_order_value)->toBe('150.0000')
        ->and((int) $sales['uae']->items_sold)->toBe(3)
        ->and((int) $sales['uae']->cancelled_orders)->toBe(1)
        ->and((int) $sales['uae']->cod_orders)->toBe(2)
        ->and($sales['omn']->currency)->toBe('OMR')
        ->and((int) $sales['omn']->orders_count)->toBe(2)
        ->and(DB::connection('reporting')->table('fact_sellers_daily')->where('country_code', 'uae')->where('report_date', '2026-11-10')->value('selling_sellers'))->toEqual(1)
        ->and((string) DB::connection('reporting')->table('fact_revenue_daily')->where('country_code', 'uae')->where('account_code', 'commission_revenue')->value('amount'))->toBe('15.0000')
        ->and(EtlRun::query()->where('status', EtlRun::SUCCEEDED)->count())->toBe(16)
        ->and(EtlRun::query()->where('trigger', 'backfill')->count())->toBe(16);
});

it('backfills a date range and records a failed country without stopping the others', function () {
    reportingOrders('uae', CarbonImmutable::parse('2026-11-02 12:00', 'Asia/Dubai'));
    $database = config('database.connections.country_kwt.database');
    config(['database.connections.country_kwt.database' => 'nawy_test_missing_reporting_source']);
    DB::purge('country_kwt');

    try {
        $this->artisan('nawy:reporting:etl', ['--from' => '2026-11-01', '--to' => '2026-11-03', '--country' => ['uae', 'kwt']])->assertFailed();
    } finally {
        config(['database.connections.country_kwt.database' => $database]);
        DB::purge('country_kwt');
    }

    expect(DB::connection('reporting')->table('fact_sellers_daily')->where('country_code', 'uae')->count())->toBe(3)
        ->and(DB::connection('reporting')->table('fact_sales_daily')->where('country_code', 'uae')->pluck('report_date')->map(fn ($date) => substr((string) $date, 0, 10))->all())->toBe(['2026-11-02'])
        ->and(EtlRun::query()->where('country_code', 'kwt')->value('status'))->toBe(EtlRun::FAILED)
        ->and(EtlRun::query()->where('country_code', 'kwt')->value('error'))->not->toBeNull()
        ->and(DB::connection('reporting')->table('fact_sellers_daily')->where('country_code', 'kwt')->count())->toBe(0);
});

it('runs nightly at the reporting ETL time for yesterday in each country', function () {
    $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event): array => [trim(Str::after($event->command, 'artisan'), " '") => $event]);

    expect($events['nawy:reporting:etl']->expression)->toBe('30 1 * * *')
        ->and($events['nawy:reporting:etl']->timezone)->toBe('Asia/Muscat')
        ->and(app(ReportingEtl::class)->yesterday(Country::query()->where('code', 'egy')->sole())->toDateString())
        ->toBe(CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString());
});

describe('cross-country report page', function () {
    it('shows figures per country and currency from the reporting database only', function () {
        reportingOrders('uae', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Dubai'));
        reportingOrders('ksa', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Riyadh'));
        $this->artisan('nawy:reporting:etl', ['--date' => '2026-11-10'])->assertSuccessful();

        $connections = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$connections): void {
            // The panel shell's notification bell is the only country query allowed on the page.
            if (! str_contains($query->sql, 'customer_notifications')) {
                $connections[] = $query->connectionName;
            }
        });

        $this->actingAs(adminAccount())->get(onPanel('admin', 'reports/cross-country?from=2026-11-01&to=2026-11-30'))
            ->assertOk()
            ->assertSee('data-sales="uae-AED"', false)
            ->assertSee('data-sales="ksa-SAR"', false)
            ->assertSee('data-etl-run="uae"', false)
            ->assertSee('Reload now');

        expect(collect($connections)->filter(fn (string $name): bool => str_starts_with($name, 'country_'))->all())->toBe([])
            ->and($connections)->toContain('reporting');
    });

    it('limits a country admin to their own countries and exports CSV', function () {
        reportingOrders('uae', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Dubai'));
        reportingOrders('ksa', CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Riyadh'));
        $this->artisan('nawy:reporting:etl', ['--date' => '2026-11-10'])->assertSuccessful();
        $finance = adminAccount('uae', 'finance');

        $this->actingAs($finance)->get(onPanel('admin', 'reports/cross-country?from=2026-11-01&to=2026-11-30&countries[]=ksa&countries[]=uae'))
            ->assertOk()->assertSee('data-sales="uae-AED"', false)->assertDontSee('data-sales="ksa-SAR"', false)->assertDontSee('Reload now');
        $this->actingAs($finance)->post(onPanel('admin', 'reports/cross-country/run'))->assertForbidden();

        $csv = $this->actingAs($finance)->get(onPanel('admin', 'reports/cross-country?from=2026-11-01&to=2026-11-30&export=csv'))->assertOk()->streamedContent();
        expect($csv)->toContain('report_date,country_code,currency')->toContain('2026-11-10,uae,AED,2')->not->toContain(',ksa,');

        $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'reports/cross-country'))->assertForbidden();
    });

    it('lets a super admin reload a day now', function () {
        reportingOrders('qat', CarbonImmutable::parse('2026-11-12 12:00', 'Asia/Qatar'));

        $this->actingAs($admin = adminAccount())->post(onPanel('admin', 'reports/cross-country/run'), ['date' => '2026-11-12'])->assertRedirect();

        expect(DB::connection('reporting')->table('fact_sales_daily')->where('country_code', 'qat')->value('orders_count'))->toEqual(2)
            ->and(EtlRun::query()->where('trigger', 'manual')->where('triggered_by_user_id', $admin->id)->count())->toBe(8);
    });
});
