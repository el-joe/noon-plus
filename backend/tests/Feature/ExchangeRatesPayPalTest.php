<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Models\ExchangeRate;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRateProvider;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRates;
use App\Domains\Finance\Support\ExchangeRates\OpenExchangeRatesProvider;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentRefunds;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

function paypalConfig(string $country = 'uae'): CountryGatewayConfig
{
    return CountryGatewayConfig::query()->where('gateway', PaymentMethod::PayPal)
        ->whereBelongsTo(Country::query()->where('code', $country)->sole())->sole();
}

function enablePayPalPayload(string $currency = 'AED'): array
{
    return ['is_enabled' => '1', 'mode' => 'sandbox', 'currencies' => $currency, 'min_amount' => '', 'max_amount' => '', 'sort_order' => '3', 'fee_percent' => '', 'fee_fixed' => ''];
}

function usdRate(string $base, string $rate, mixed $at = null): ExchangeRate
{
    return app(ExchangeRates::class)->record($base, 'USD', $rate, $at ?? now()->subMinute());
}

describe('exchange rates admin', function () {
    it('lets finance add an audited rate and blocks other roles', function () {
        $finance = adminAccount('uae', 'finance');

        $this->actingAs($finance)->get(onPanel('admin', 'countries/exchange-rates'))->assertOk()
            ->assertSee('1 AED → USD')->assertSee('Needs a rate');

        $this->actingAs($finance)->post(onPanel('admin', 'countries/exchange-rates'), [
            'base_currency' => 'AED', 'quote_currency' => 'USD', 'rate' => '0.2723', 'effective_at' => '', 'note' => 'Peg',
        ])->assertSessionHasNoErrors();

        $rate = ExchangeRate::query()->sole();
        expect($rate)->base_currency->toBe('AED')->quote_currency->toBe('USD')->rate->toBe('0.2723000000')->source->toBe('manual')->created_by->toBe($finance->id)
            ->and(Activity::query()->where('log_name', 'exchange_rates')->where('subject_id', $rate->id)->where('event', 'created')->exists())->toBeTrue();

        $this->actingAs($finance)->get(onPanel('admin', 'countries/exchange-rates'))->assertOk()->assertSee('0.2723')->assertSee('Can be enabled');

        $this->actingAs($finance)->post(onPanel('admin', 'countries/exchange-rates'), ['base_currency' => 'AED', 'quote_currency' => 'AED', 'rate' => '1'])
            ->assertSessionHasErrors('quote_currency');
        $this->actingAs($finance)->post(onPanel('admin', 'countries/exchange-rates'), ['base_currency' => 'AED', 'quote_currency' => 'USD', 'rate' => '0'])
            ->assertSessionHasErrors('rate');
        $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'countries/exchange-rates'))->assertForbidden();
        $this->actingAs(adminAccount('uae', 'marketing'))->post(onPanel('admin', 'countries/exchange-rates'), ['base_currency' => 'AED', 'quote_currency' => 'USD', 'rate' => '1'])->assertForbidden();
    });

    it('uses the newest rate already in effect and enters times in Oman time', function () {
        $admin = adminAccount();
        usdRate('AED', '0.2700', now()->subDays(3));
        usdRate('AED', '0.2723', now()->subDay());
        usdRate('AED', '0.3000', now()->addDay());

        expect(app(ExchangeRates::class)->current('AED', 'USD')->rate)->toBe('0.2723000000')
            ->and(app(ExchangeRates::class)->current('AED', 'USD', now()->subDays(2))->rate)->toBe('0.2700000000');

        $this->actingAs($admin)->post(onPanel('admin', 'countries/exchange-rates'), ['base_currency' => 'OMR', 'quote_currency' => 'USD', 'rate' => '2.6008', 'effective_at' => '2026-12-01T08:00']);
        expect(ExchangeRate::query()->where('base_currency', 'OMR')->sole()->effective_at->toIso8601String())->toBe('2026-12-01T04:00:00+00:00');
    });

    it('syncs from the configured provider; the manual driver fetches nothing', function () {
        $this->artisan('nawy:exchange-rates:sync')->assertSuccessful();
        expect(ExchangeRate::query()->count())->toBe(0);

        Http::fake(['openexchangerates.org/*' => Http::response(['base' => 'USD', 'rates' => ['AED' => 3.6725, 'OMR' => 0.38451, 'SAR' => 3.75, 'KWD' => 0.3074, 'EGP' => 48.5, 'QAR' => 3.64, 'BHD' => 0.376, 'JOD' => 0.709]])]);
        app()->instance(ExchangeRateProvider::class, new OpenExchangeRatesProvider('app-id-test'));
        app()->forgetInstance(ExchangeRates::class);

        $this->artisan('nawy:exchange-rates:sync')->assertSuccessful();

        expect(ExchangeRate::query()->count())->toBe(8)
            ->and(app(ExchangeRates::class)->current('AED', 'USD')->rate)->toBe('0.2722940776')
            ->and(app(ExchangeRates::class)->current('AED', 'USD')->source)->toBe('openexchangerates');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'app_id=app-id-test'));
    });
});

describe('PayPal in USD', function () {
    it('cannot be enabled or offered until the country currency has a USD rate', function () {
        $admin = adminAccount('uae', 'finance');

        $this->actingAs($admin)->put(onPanel('admin', 'countries/payment-gateways/'.paypalConfig()->id), enablePayPalPayload())->assertSessionHasErrors('is_enabled');
        expect(paypalConfig()->is_enabled)->toBeFalse();

        usdRate('AED', '0.2723');
        $this->actingAs($admin)->put(onPanel('admin', 'countries/payment-gateways/'.paypalConfig()->id), enablePayPalPayload())->assertSessionHasNoErrors();
        expect(paypalConfig()->is_enabled)->toBeTrue();

        $methods = $this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=100.00')->assertOk()->json('data');
        $paypal = collect($methods)->firstWhere('code', 'paypal');
        expect($paypal['charge'])->toBe(['currency' => 'USD', 'amount' => '27.23', 'rate' => '0.2723'])
            ->and(collect($methods)->firstWhere('code', 'stripe')['charge'])->toBeNull();

        // Another country without a rate still cannot.
        $this->actingAs(adminAccount())->put(onPanel('admin', 'countries/payment-gateways/'.paypalConfig('ksa')->id).'?country=ksa', enablePayPalPayload('SAR'))->assertSessionHasErrors('is_enabled');
    });

    it('charges USD at the stored rate, keeps the order money in AED and refunds with the stored rate', function () {
        usdRate('AED', '0.2723');
        paypalConfig()->update(['is_enabled' => true]);

        [$order, , $token] = placeOrderVia('paypal', '100.00');
        $payment = $order->payments()->sole();

        expect($payment)->currency->toBe('AED')->amount->toBe('100.0000')
            ->charge_currency->toBe('USD')->charge_amount->toBe('27.2300')->exchange_rate->toBe('0.2723000000')
            ->and($payment->client_data)->toBe(['amount' => '27.23']);

        // A newer rate does not touch this payment.
        usdRate('AED', '0.3000', now()->subSecond());

        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $payment = app(PaymentAttempts::class)->refresh($payment);

        expect($payment)->status->toBe(PaymentStatus::Captured)->captured_amount->toBe('100.0000')->charge_amount->toBe('27.2300')
            ->and($order->fresh()->payment_status)->toBe('captured');
        assertLedgerBalanced();

        $this->withToken($token)->getJson("/api/v1/uae-en/orders/{$order->number}")->assertOk()
            ->assertJsonPath('data.payment.charge.amount', ['amount' => '27.23', 'currency' => 'USD'])
            ->assertJsonPath('data.payment.charge.rate', '0.2723');

        $refunds = app(PaymentRefunds::class);
        $first = $refunds->refund($payment, Money::of('30.00', 'AED'), 'return:paypal:1', ActorType::Admin, 1);
        $last = $refunds->refund($payment->fresh(), Money::of('70.00', 'AED'), 'return:paypal:2', ActorType::System);

        expect($first)->amount->toBe('30.0000')->charge_amount->toBe('8.1700')
            ->and($last)->amount->toBe('70.0000')->charge_amount->toBe('19.0600')
            ->and(array_column(FakeGateway::refunds(), 'amount'))->toBe(['8.17', '19.06'])
            ->and($payment->fresh())->status->toBe(PaymentStatus::Refunded)->refunded_amount->toBe('100.0000');
    });

    it('charges a 3-decimal currency in USD and reviews a provider amount that differs', function () {
        $this->actAsCountry('omn');
        usdRate('OMR', '2.6008');
        paypalConfig('omn')->update(['is_enabled' => true]);

        [$order] = placeOrderVia('paypal', '10.000', 'omn-en');
        $payment = $order->payments()->sole();
        expect($payment)->currency->toBe('OMR')->charge_amount->toBe('26.0100');

        $payment = app(PaymentAttempts::class)->applyOutcome($payment, new GatewayOutcome(PaymentStatus::Captured, $payment->gateway_reference, '25.00'), 'webhook');

        expect($payment)->status->toBe(PaymentStatus::UnderReview)->captured_amount->toBe('9.6120')
            ->failure_code->toBe('amount_mismatch');
    });

    it('fails the attempt cleanly when the rate is gone', function () {
        $rate = usdRate('AED', '0.2723');
        paypalConfig()->update(['is_enabled' => true]);
        [, $token] = customerWithToken();
        $listing = sellableListing('50.00');
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $rate->delete();

        $this->withToken($token)->postJson('/api/v1/uae-en/orders', [
            'payment_method' => 'paypal', 'address' => ['name' => 'Sara', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');

        expect(Payment::query()->count())->toBe(0);
    });
});
