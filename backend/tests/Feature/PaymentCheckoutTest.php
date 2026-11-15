<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Exceptions\SubOrderTransitionRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\SubOrderStateMachine;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * Codes of the payment methods offered for an amount in a market.
 *
 * @return array<int, string>
 */
function methodCodes(string $market, string $amount): array
{
    return array_column(test()->getJson("/api/v1/{$market}/checkout/payment-methods?amount={$amount}")->assertOk()->json('data'), 'code');
}

/**
 * Put one listing in the customer's cart and place the order with a method.
 *
 * @return array{0: TestResponse, 1: string, 2: Listing}
 */
function placeWith(string $method, string $price = '100.00', string $market = 'uae-en', int $stock = 5): array
{
    [, $token] = customerWithToken();
    $listing = sellableListing($price, stock: $stock);
    test()->withToken($token)->putJson("/api/v1/{$market}/cart/items", ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();

    $response = test()->withToken($token)->postJson("/api/v1/{$market}/orders", [
        'payment_method' => $method,
        'address' => ['name' => 'Sara Ahmed', 'phone' => checkoutPhoneFor($market), 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'],
    ]);

    return [$response, $token, $listing];
}

describe('payment methods', function () {
    it('lists the UAE methods recommended first, without disabled PayPal, COD last with its fee', function () {
        $response = $this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=250.00')->assertOk();

        expect(array_column($response->json('data'), 'code'))->toBe(['stripe', 'paytabs', 'tabby', 'tamara', 'cod'])
            ->and($response->json('data.0.recommended'))->toBeTrue()
            ->and($response->json('data.1.recommended'))->toBeFalse()
            ->and($response->json('data.4.fee'))->toBe(['amount' => '10.00', 'currency' => 'AED'])
            ->and($response->json('data.0.fee'))->toBeNull()
            ->and($response->json('meta.currency'))->toBe('AED');
    });

    it('shows Thawani first in Oman and only OMR gateways', function () {
        expect(methodCodes('omn-ar', '45.500'))->toBe(['thawani', 'paytabs', 'stripe', 'cod']);
    });

    it('hides BNPL outside its amount limits', function () {
        expect(methodCodes('uae-en', '50.00'))->toBe(['stripe', 'paytabs', 'tabby', 'cod'])
            ->and(methodCodes('uae-en', '6000.00'))->toBe(['stripe', 'paytabs', 'cod']);
    });

    it('hides Stripe for 3-decimal amounts it cannot charge', function () {
        expect(methodCodes('kwt-en', '12.345'))->toBe(['paytabs', 'cod'])
            ->and(methodCodes('kwt-en', '12.340'))->toBe(['paytabs', 'stripe', 'cod']);
    });

    it('honours disabled gateways, currency lists, min/max and the COD settings', function () {
        $uae = Country::query()->where('code', 'uae')->sole();
        CountryGatewayConfig::query()->where('country_id', $uae->id)->where('gateway', PaymentMethod::PayTabs)->update(['currencies' => json_encode(['USD'])]);
        CountryGatewayConfig::query()->where('country_id', $uae->id)->where('gateway', PaymentMethod::Tabby)->update(['is_enabled' => false]);
        CountryGatewayConfig::query()->where('country_id', $uae->id)->where('gateway', PaymentMethod::Stripe)->update(['min_amount' => '300']);
        app(SettingsService::class)->set('cod.max_order_amount', '200.00', $uae);

        expect(methodCodes('uae-en', '250.00'))->toBe(['tamara']);

        app(SettingsService::class)->set('cod.enabled', false, $uae);
        expect(methodCodes('uae-en', '150.00'))->toBe(['tamara']);
    });

    it('keeps each country to its own gateways', function () {
        expect(methodCodes('egy-en', '500.00'))->toBe(['paytabs', 'stripe', 'cod'])
            ->and(methodCodes('jor-ar', '20.000'))->toBe(['paytabs', 'stripe', 'cod']);
    });

    it('validates the amount for the currency decimals', function () {
        $this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=10.005')->assertUnprocessable()->assertJsonValidationErrors('amount', 'error.fields');
        $this->getJson('/api/v1/omn-en/checkout/payment-methods?amount=10.005')->assertOk();
        $this->getJson('/api/v1/uae-en/checkout/payment-methods')->assertUnprocessable();
        $this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=-1')->assertUnprocessable();
    });
});

describe('placing an order', function () {
    it('creates a pending card payment with a redirect and holds the order', function () {
        [$response] = placeWith('stripe');

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'stripe')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.amount', ['amount' => '100.00', 'currency' => 'AED'])
            ->assertJsonPath('data.payment.next_action.type', 'redirect');

        $payment = Payment::query()->sole();
        expect($response->json('data.payment.next_action.url'))->toBe('https://pay.fake.test/stripe/'.FakeGateway::referenceFor($payment->reference, PaymentMethod::Stripe))
            ->and($payment->expires_at->equalTo(Order::query()->sole()->payment_due_at))->toBeTrue()
            ->and(Order::query()->sole()->payment_due_at->diffInMinutes(now(), true))->toEqualWithDelta(30, 1)
            ->and($payment->idempotency_key)->toBe('order:'.$payment->order_id.':attempt:1')
            ->and($payment->statusHistory()->sole()->to_status)->toBe(PaymentStatus::Pending)
            ->and($response->json('data.payment'))->not->toHaveKey('idempotency_key');
    });

    it('treats "card" as the recommended gateway of the country', function () {
        placeWith('card')[0]->assertCreated()->assertJsonPath('data.payment_method', 'stripe');

        $this->actAsCountry('omn');
        placeWith('card', '10.500', 'omn-en')[0]->assertCreated()->assertJsonPath('data.payment_method', 'thawani');
    });

    it('places COD without a hold window and with nothing to redirect to', function () {
        placeWith('cod')[0]->assertCreated()
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_due_at', null)
            ->assertJsonPath('data.payment.next_action.type', 'none')
            ->assertJsonPath('data.totals.cod_fee.amount', '10.00');
    });

    it('refuses a method that is not offered for the order', function (string $method) {
        [$response] = placeWith($method, '20.00');

        $response->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        expect(Order::query()->count())->toBe(0);
    })->with(['paypal', 'tamara', 'thawani', 'wallet', 'gift_card']);

    it('keeps the order on hold for a retry when the gateway cannot start', function () {
        FakeGateway::failNext('create');

        [$response] = placeWith('stripe');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.payment.next_action.type', 'retry')
            ->assertJsonPath('data.payment.failure.code', 'gateway_unavailable');
    });

    it('confirms a sub-order only once the payment is captured (or COD)', function () {
        placeWith('stripe');
        $subOrder = Order::query()->sole()->subOrders()->sole();

        expect(fn () => app(SubOrderStateMachine::class)->transition($subOrder, SubOrderStatus::Confirmed, ActorType::Admin, 1))
            ->toThrow(SubOrderTransitionRefused::class, 'payment');

        $payment = Payment::query()->sole();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        app(PaymentAttempts::class)->refresh($payment);

        expect(app(SubOrderStateMachine::class)->transition($subOrder->fresh(), SubOrderStatus::Confirmed, ActorType::Admin, 1)->status)->toBe(SubOrderStatus::Confirmed);
    });
});

describe('after the gateway', function () {
    it('verifies the result on return and redirects to the storefront', function () {
        [$response] = placeWith('stripe');
        $payment = Payment::query()->sole();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);

        $this->get("/api/v1/uae-en/payments/{$payment->reference}/return")
            ->assertRedirect(config('nawy.payments.storefront_url').'/uae-en/checkout/payment?'.http_build_query(['order' => $response->json('data.number'), 'payment' => $payment->reference, 'status' => 'captured']));

        expect($payment->fresh())->status->toBe(PaymentStatus::Captured)->captured_amount->toBe('100.0000')
            ->and(Order::query()->sole()->payment_status)->toBe('captured');
    });

    it('does not trust the return redirect without the gateway confirming it', function () {
        placeWith('stripe');
        $payment = Payment::query()->sole();

        $this->post("/api/v1/uae-en/payments/{$payment->reference}/return")->assertRedirect();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('fails a cancelled payment, unless the gateway says it was paid', function () {
        placeWith('stripe');
        $payment = Payment::query()->sole();

        $this->get("/api/v1/uae-en/payments/{$payment->reference}/cancel")->assertRedirect();
        expect($payment->fresh())->status->toBe(PaymentStatus::Failed)->failure_code->toBe('cancelled');

        [, $token] = placeWith('paytabs');
        $paid = Payment::query()->latest('id')->first();
        FakeGateway::willReport($paid->gateway_reference, PaymentStatus::Captured);
        $this->get("/api/v1/uae-en/payments/{$paid->reference}/cancel")->assertRedirect();
        expect($paid->fresh()->status)->toBe(PaymentStatus::Captured);
    });

    it('captures a BNPL authorisation straight away', function () {
        placeWith('tabby', '300.00');
        $payment = Payment::query()->sole();
        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Authorized);

        app(PaymentAttempts::class)->refresh($payment);

        expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
            ->and($payment->statusHistory()->pluck('to_status')->map->value->all())->toBe(['pending', 'authorized', 'captured'])
            ->and(Order::query()->sole()->payment_status)->toBe('captured');
    });

    it('lets the customer poll the payment while 3DS runs', function () {
        [$response, $token] = placeWith('stripe');
        $number = $response->json('data.number');
        $payment = Payment::query()->sole();

        $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$number}/payments/verify")->assertOk()->assertJsonPath('data.payment.status', 'pending');

        FakeGateway::willReport($payment->gateway_reference, PaymentStatus::Captured);
        $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$number}/payments/verify")->assertOk()
            ->assertJsonPath('data.payment_status', 'captured')
            ->assertJsonPath('data.payment.next_action.type', 'none');
    });
});

describe('retrying', function () {
    it('retries a failed payment with another method within the hold window', function () {
        [$response, $token] = placeWith('stripe');
        $number = $response->json('data.number');
        $first = Payment::query()->sole();
        FakeGateway::willReport($first->gateway_reference, PaymentStatus::Failed);

        $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$number}/payments", ['payment_method' => 'paytabs'])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'paytabs')
            ->assertJsonPath('data.payment.method', 'paytabs')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.status', 'placed');

        expect($first->fresh())->status->toBe(PaymentStatus::Failed)->failure_code->toBe('declined')
            ->and(Payment::query()->count())->toBe(2);
    });

    it('supersedes an abandoned pending attempt', function () {
        [$response, $token] = placeWith('stripe');
        $first = Payment::query()->sole();

        $this->withToken($token)->postJson("/api/v1/uae-en/orders/{$response->json('data.number')}/payments", ['payment_method' => 'stripe'])->assertCreated();

        expect($first->fresh())->status->toBe(PaymentStatus::Failed)->failure_code->toBe('superseded');
    });

    it('refuses a retry once paid, after the hold window, with COD, over the attempt limit or for another customer', function () {
        [$response, $token] = placeWith('stripe');
        $number = $response->json('data.number');
        $retry = fn (string $method = 'paytabs', ?string $as = null) => $this->withToken($as ?? $token)->postJson("/api/v1/uae-en/orders/{$number}/payments", ['payment_method' => $method]);

        $retry('cod')->assertStatus(409)->assertJsonPath('error.code', 'method_not_allowed');
        $retry('paypal')->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $retry()->assertCreated();
        $retry()->assertCreated();
        $retry()->assertStatus(409)->assertJsonPath('error.code', 'too_many_attempts');

        app('auth')->forgetGuards();
        [, $stranger] = customerWithToken();
        $retry('paytabs', $stranger)->assertNotFound();
        app('auth')->forgetGuards();

        $latest = Payment::query()->latest('id')->first();
        FakeGateway::willReport($latest->gateway_reference, PaymentStatus::Captured);
        $retry()->assertStatus(409)->assertJsonPath('error.code', 'already_paid');

        [$late, $lateToken] = placeWith('stripe');
        $this->travel(31)->minutes();
        $this->withToken($lateToken)->postJson("/api/v1/uae-en/orders/{$late->json('data.number')}/payments", ['payment_method' => 'paytabs'])
            ->assertStatus(409)->assertJsonPath('error.code', 'payment_window_closed');
    });
});

it('keeps the stock taken while the payment is pending', function () {
    [, , $listing] = placeWith('stripe', stock: 2);

    expect(app(InventoryLedger::class)->available($listing))->toBe(1);
});
