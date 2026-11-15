<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Gateways\PayPalGateway;
use App\Domains\Payments\Support\Gateways\PayTabsGateway;
use App\Domains\Payments\Support\Gateways\StripeGateway;
use App\Domains\Payments\Support\Gateways\TabbyGateway;
use App\Domains\Payments\Support\Gateways\TamaraGateway;
use App\Domains\Payments\Support\Gateways\ThawaniGateway;
use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    Http::preventStrayRequests();
});

/**
 * A gateway config (not saved) for adapter tests.
 *
 * @param  array<string, string>  $credentials
 */
function gatewayConfig(PaymentMethod $method, string $countryCode, array $credentials, GatewayMode $mode = GatewayMode::Sandbox): CountryGatewayConfig
{
    $country = Country::query()->where('code', $countryCode)->sole();
    $config = new CountryGatewayConfig(['gateway' => $method, 'mode' => $mode, 'credentials' => $credentials, 'currencies' => [$country->currency]]);
    $config->setRelation('country', $country);

    return $config;
}

function paymentRequest(string $amount, string $currency): PaymentRequest
{
    return new PaymentRequest(
        reference: '9b2f6a3e-0000-4000-8000-000000000001', orderNumber: 'NW-OM-1000001',
        amount: Money::of($amount, $currency), description: 'Nawy order NW-OM-1000001',
        customerEmail: 'sara@example.com', customerName: 'Sara Ahmed', customerPhone: '+96890000000',
        countryIso2: 'OM', locale: 'en',
        returnUrl: 'https://api.test/return', cancelUrl: 'https://api.test/cancel', notifyUrl: 'https://api.test/webhook',
        idempotencyKey: 'order:1:attempt:1', shippingAddress: ['city' => 'Muscat', 'street' => 'Way 3011'],
    );
}

/**
 * A payment row in memory (adapters only read its fields).
 */
function paymentFor(string $gatewayReference, string $amount, string $currency, string $captured = '0'): Payment
{
    $payment = new Payment;
    $payment->forceFill(['reference' => 'ref-1', 'gateway_reference' => $gatewayReference, 'amount' => $amount, 'currency' => $currency, 'captured_amount' => $captured]);

    return $payment;
}

/**
 * @param  array<string, string>  $headers
 */
function webhookRequest(string $body, array $headers = [], array $query = []): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/webhook?'.http_build_query($query), 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

describe('Thawani', function () {
    it('converts OMR to integer baisa only inside the adapter', function (string $omr, int $baisa) {
        expect(ThawaniGateway::toBaisa(Money::of($omr, 'OMR')))->toBe($baisa)
            ->and(ThawaniGateway::fromBaisa($baisa))->toBe(Money::of($omr, 'OMR')->getAmount()->__toString());
    })->with([
        ['45.500', 45500],
        ['0.100', 100],
        ['1.005', 1005],
        ['1234.999', 1234999],
    ]);

    it('refuses any currency but OMR', function () {
        ThawaniGateway::toBaisa(Money::of('10.00', 'AED'));
    })->throws(GatewayError::class);

    it('creates a sandbox checkout session in baisa and returns the hosted page', function () {
        Http::fake(['uatcheckout.thawani.om/api/v1/checkout/session' => Http::response(['success' => true, 'data' => ['session_id' => 'checkout_abc']])]);
        $gateway = new ThawaniGateway(gatewayConfig(PaymentMethod::Thawani, 'omn', ['secret_key' => 'sk', 'publishable_key' => 'pk', 'webhook_secret' => 'wh']));

        $session = $gateway->createPayment(paymentRequest('45.500', 'OMR'));

        expect($session->gatewayReference)->toBe('checkout_abc')
            ->and($session->redirectUrl)->toBe('https://uatcheckout.thawani.om/pay/checkout_abc?key=pk');
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('thawani-api-key', 'sk')
            && $request['products'][0]['unit_amount'] === 45500
            && $request['client_reference_id'] === '9b2f6a3e-0000-4000-8000-000000000001');
    });

    it('uses the live host in live mode and reads a paid session back in OMR', function () {
        Http::fake(['checkout.thawani.om/api/v1/checkout/session/checkout_abc' => Http::response(['data' => ['payment_status' => 'paid', 'total_amount' => 45500]])]);
        $gateway = new ThawaniGateway(gatewayConfig(PaymentMethod::Thawani, 'omn', ['secret_key' => 'sk'], GatewayMode::Live));

        $outcome = $gateway->verify(paymentFor('checkout_abc', '45.5000', 'OMR'));

        expect($outcome->status)->toBe(PaymentStatus::Captured)->and($outcome->amount)->toBe('45.500');
    });

    it('refunds whole payments only', function () {
        $gateway = new ThawaniGateway(gatewayConfig(PaymentMethod::Thawani, 'omn', ['secret_key' => 'sk']));

        $gateway->refund(paymentFor('checkout_abc', '45.500', 'OMR', '45.500'), Money::of('10.000', 'OMR'), 'r1');
    })->throws(GatewayError::class, 'whole payments');

    it('verifies the webhook signature over body and timestamp', function () {
        $gateway = new ThawaniGateway(gatewayConfig(PaymentMethod::Thawani, 'omn', ['webhook_secret' => 'wh-secret']));
        $body = json_encode(['event_type' => 'checkout.completed', 'data' => ['session_id' => 'checkout_abc', 'client_reference_id' => 'ref-1', 'payment_status' => 'paid', 'total_amount' => 1005]]);
        $now = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $body.'-'.$now, 'wh-secret');

        $notification = $gateway->parseWebhook(webhookRequest($body, ['thawani-signature' => $signature, 'thawani-timestamp' => $now]));

        expect($notification->eventId)->toBe('checkout.completed:checkout_abc')
            ->and($notification->outcome->status)->toBe(PaymentStatus::Captured)
            ->and($notification->outcome->amount)->toBe('1.005');

        expect(fn () => $gateway->parseWebhook(webhookRequest($body, ['thawani-signature' => $signature, 'thawani-timestamp' => (string) ($now + 1)])))
            ->toThrow(InvalidWebhookSignature::class);
    });

    it('QC-048: refuses a correctly signed Thawani notification outside the replay window', function () {
        $gateway = new ThawaniGateway(gatewayConfig(PaymentMethod::Thawani, 'omn', ['webhook_secret' => 'wh-secret']));
        $body = json_encode(['event_type' => 'checkout.completed', 'data' => ['session_id' => 'checkout_abc', 'payment_status' => 'paid']]);
        $old = (string) now()->subMinutes(10)->getTimestamp();

        expect(fn () => $gateway->parseWebhook(webhookRequest($body, ['thawani-signature' => hash_hmac('sha256', $body.'-'.$old, 'wh-secret'), 'thawani-timestamp' => $old])))
            ->toThrow(InvalidWebhookSignature::class, 'replay window');
    });
});

describe('Stripe', function () {
    it('sends minor units, including 3-decimal currencies in multiples of 10', function (string $amount, string $currency, int $minor) {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $gateway = new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'uae', ['secret_key' => 'sk_test']));

        $session = $gateway->createPayment(paymentRequest($amount, $currency));

        expect($session->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Idempotency-Key', 'order:1:attempt:1')
            && $request->hasHeader('Authorization', 'Bearer sk_test')
            && (int) $request['line_items'][0]['price_data']['unit_amount'] === $minor
            && $request['line_items'][0]['price_data']['currency'] === strtolower($currency));
    })->with([
        ['2338.20', 'AED', 233820],
        ['45.500', 'OMR', 45500],
        ['12.340', 'KWD', 12340],
        ['7.010', 'BHD', 7010],
        ['99.990', 'JOD', 99990],
    ]);

    it('refuses 3-decimal amounts Stripe cannot charge', function () {
        expect(StripeGateway::acceptsAmount(Money::of('45.505', 'OMR')))->toBeFalse()
            ->and(StripeGateway::acceptsAmount(Money::of('45.50', 'AED')))->toBeTrue();

        (new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'kwt', ['secret_key' => 'sk'])))->createPayment(paymentRequest('12.345', 'KWD'));
    })->throws(GatewayError::class);

    it('maps a paid session back from minor units and refunds through the payment intent', function () {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 12340, 'currency' => 'kwd', 'payment_intent' => 'pi_1']),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
        ]);
        $gateway = new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'kwt', ['secret_key' => 'sk']));
        $payment = paymentFor('cs_1', '12.340', 'KWD', '12.340');

        expect($gateway->verify($payment))->status->toBe(PaymentStatus::Captured)->amount->toBe('12.340');
        expect($gateway->refund($payment, Money::of('2.000', 'KWD'), 'refund-key')->refundReference)->toBe('re_1');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/refunds') && (int) $request['amount'] === 2000 && $request->hasHeader('Idempotency-Key', 'refund-key'));
    });

    it('accepts a valid signature and refuses tampered or stale ones', function () {
        $gateway = new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'uae', ['webhook_secret' => 'whsec_1']));
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['object' => 'checkout.session', 'id' => 'cs_1', 'client_reference_id' => 'ref-1', 'payment_status' => 'paid', 'amount_total' => 10000, 'currency' => 'aed']]]);
        $t = now()->getTimestamp();
        $sign = fn (int $t, string $body) => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'whsec_1');

        $notification = $gateway->parseWebhook(webhookRequest($body, ['Stripe-Signature' => $sign($t, $body)]));
        expect($notification->eventId)->toBe('evt_1')->and($notification->outcome->status)->toBe(PaymentStatus::Captured)->and($notification->outcome->amount)->toBe('100.00');

        expect(fn () => $gateway->parseWebhook(webhookRequest(str_replace('10000', '1', $body), ['Stripe-Signature' => $sign($t, $body)])))->toThrow(InvalidWebhookSignature::class)
            ->and(fn () => $gateway->parseWebhook(webhookRequest($body, ['Stripe-Signature' => $sign($t - 600, $body)])))->toThrow(InvalidWebhookSignature::class)
            ->and(fn () => $gateway->parseWebhook(webhookRequest($body)))->toThrow(InvalidWebhookSignature::class);
    });
});

describe('PayPal', function () {
    it('creates an order with a decimal amount and the approval link', function () {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'PP-1', 'links' => [['rel' => 'self', 'href' => 'x'], ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PP-1']]]),
        ]);
        $gateway = new PayPalGateway(gatewayConfig(PaymentMethod::PayPal, 'uae', ['client_id' => 'id', 'client_secret' => 'secret']));

        $session = $gateway->createPayment(paymentRequest('2338.20', 'USD'));

        expect($session->gatewayReference)->toBe('PP-1')->and($session->redirectUrl)->toContain('token=PP-1');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '2338.20']
            && $request->hasHeader('PayPal-Request-Id', 'order:1:attempt:1'));
    });

    it('refuses 3-decimal currencies', function () {
        (new PayPalGateway(gatewayConfig(PaymentMethod::PayPal, 'omn', ['client_id' => 'id', 'client_secret' => 's'])))->createPayment(paymentRequest('45.500', 'OMR'));
    })->throws(GatewayError::class);

    it('verifies webhooks with PayPal and refuses when PayPal says no', function (string $answer, bool $valid) {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $answer]),
        ]);
        $gateway = new PayPalGateway(gatewayConfig(PaymentMethod::PayPal, 'uae', ['client_id' => 'id', 'client_secret' => 's', 'webhook_id' => 'WH-ID']));
        $body = json_encode(['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['amount' => ['value' => '10.00'], 'custom_id' => 'ref-1', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-1']]]]);
        $headers = ['PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert', 'PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-SIG' => 'sig', 'PAYPAL-TRANSMISSION-TIME' => '2026-11-14T10:00:00Z'];

        if (! $valid) {
            expect(fn () => $gateway->parseWebhook(webhookRequest($body, $headers)))->toThrow(InvalidWebhookSignature::class);

            return;
        }

        $notification = $gateway->parseWebhook(webhookRequest($body, $headers));
        expect($notification->eventId)->toBe('WH-EVT-1')->and($notification->gatewayReference)->toBe('PP-1')->and($notification->outcome->status)->toBe(PaymentStatus::Captured);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), 'verify-webhook-signature') && $request['webhook_id'] === 'WH-ID' && $request['transmission_id'] === 't1');
    })->with([['SUCCESS', true], ['FAILURE', false]]);
});

describe('PayTabs', function () {
    it('sends cart_amount as an exact number with the currency decimals to the regional endpoint', function (string $country, string $amount, string $currency, string $host) {
        Http::fake(["{$host}/payment/request" => Http::response(['tran_ref' => 'TST123', 'redirect_url' => "https://{$host}/payment/page/TST123"])]);
        $gateway = new PayTabsGateway(gatewayConfig(PaymentMethod::PayTabs, $country, ['profile_id' => '1234', 'server_key' => 'SK']));

        $session = $gateway->createPayment(paymentRequest($amount, $currency));

        expect($session->gatewayReference)->toBe('TST123');
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('authorization', 'SK')
            && str_contains($request->body(), '"cart_amount":'.$amount.',')
            && $request['cart_currency'] === $currency && $request['profile_id'] === 1234);
    })->with([
        ['omn', '45.500', 'OMR', 'secure-oman.paytabs.com'],
        ['jor', '12.345', 'JOD', 'secure-jordan.paytabs.com'],
        ['bhr', '7.005', 'BHD', 'secure-global.paytabs.com'],
        ['kwt', '0.250', 'KWD', 'secure-global.paytabs.com'],
        ['ksa', '1500.75', 'SAR', 'secure.paytabs.sa'],
    ]);

    it('maps query results and refuses a declined refund', function () {
        Http::fake(['secure.paytabs.com/payment/query' => Http::response(['tran_ref' => 'T1', 'cart_amount' => '10.00', 'payment_result' => ['response_status' => 'D', 'response_message' => 'Declined']]),
            'secure.paytabs.com/payment/request' => Http::response(['tran_ref' => 'R1', 'payment_result' => ['response_status' => 'E', 'response_message' => 'Error']])]);
        $gateway = new PayTabsGateway(gatewayConfig(PaymentMethod::PayTabs, 'uae', ['profile_id' => '1', 'server_key' => 'SK']));
        $payment = paymentFor('T1', '10.00', 'AED', '10.00');

        expect($gateway->verify($payment))->status->toBe(PaymentStatus::Failed)->failureMessage->toBe('Declined');
        expect(fn () => $gateway->refund($payment, Money::of('5.00', 'AED'), 'k'))->toThrow(GatewayError::class);
    });

    it('verifies the callback signature (HMAC of the body with the server key)', function () {
        $gateway = new PayTabsGateway(gatewayConfig(PaymentMethod::PayTabs, 'uae', ['server_key' => 'SK']));
        $body = json_encode(['tran_ref' => 'TST123', 'tran_type' => 'Sale', 'cart_id' => 'ref-1', 'cart_amount' => '10.00', 'payment_result' => ['response_status' => 'A']]);

        $notification = $gateway->parseWebhook(webhookRequest($body, ['signature' => hash_hmac('sha256', $body, 'SK')]));
        expect($notification->eventId)->toBe('TST123')->and($notification->outcome->status)->toBe(PaymentStatus::Captured);

        expect(fn () => $gateway->parseWebhook(webhookRequest($body, ['signature' => hash_hmac('sha256', $body, 'other')])))->toThrow(InvalidWebhookSignature::class);
    });
});

describe('Tabby', function () {
    it('starts a checkout with a decimal amount and refuses a rejected customer', function () {
        Http::fakeSequence('api.tabby.ai/api/v2/checkout')
            ->push(['status' => 'created', 'payment' => ['id' => 'tabby-1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/x']]]]])
            ->push(['status' => 'rejected']);
        $gateway = new TabbyGateway(gatewayConfig(PaymentMethod::Tabby, 'kwt', ['secret_key' => 'sk', 'merchant_code' => 'NAWYKW']));

        expect($gateway->createPayment(paymentRequest('12.340', 'KWD'))->redirectUrl)->toBe('https://checkout.tabby.ai/x');
        Http::assertSent(fn (HttpRequest $request) => $request['payment']['amount'] === '12.340' && $request['merchant_code'] === 'NAWYKW');
        expect(fn () => $gateway->createPayment(paymentRequest('12.340', 'KWD')))->toThrow(GatewayError::class);
    });

    it('captures an authorisation', function () {
        Http::fake(['api.tabby.ai/api/v2/payments/tabby-1/captures' => Http::response(['id' => 'tabby-1', 'status' => 'CLOSED', 'amount' => '100.00'])]);
        $gateway = new TabbyGateway(gatewayConfig(PaymentMethod::Tabby, 'uae', ['secret_key' => 'sk']));

        expect($gateway->capture(paymentFor('tabby-1', '100.00', 'AED'), Money::of('100.00', 'AED')))->status->toBe(PaymentStatus::Captured);
        Http::assertSent(fn (HttpRequest $request) => $request['amount'] === '100.00');
    });

    it('checks the shared webhook header', function () {
        $gateway = new TabbyGateway(gatewayConfig(PaymentMethod::Tabby, 'uae', ['webhook_secret' => 'tabby-secret']));
        $body = json_encode(['id' => 'tabby-1', 'status' => 'authorized', 'amount' => '100.00']);

        expect($gateway->parseWebhook(webhookRequest($body, [TabbyGateway::WEBHOOK_HEADER => 'tabby-secret'])))
            ->eventId->toBe('tabby-1:authorized:0')
            ->outcome->status->toBe(PaymentStatus::Authorized);
        expect(fn () => $gateway->parseWebhook(webhookRequest($body, [TabbyGateway::WEBHOOK_HEADER => 'nope'])))->toThrow(InvalidWebhookSignature::class);
    });
});

/**
 * HS256 JWT signed with the notification token.
 */
function tamaraJwt(string $secret, int $exp): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
    $unsigned = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode(['iss' => 'Tamara', 'exp' => $exp]);

    return $unsigned.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $unsigned, $secret, true)), '+/', '-_'), '=');
}

describe('Tamara', function () {
    it('creates a checkout with exact number amounts in the sandbox', function () {
        Http::fake(['api-sandbox.tamara.co/checkout' => Http::response(['order_id' => 'tam-1', 'checkout_url' => 'https://checkout-sandbox.tamara.co/x'])]);
        $gateway = new TamaraGateway(gatewayConfig(PaymentMethod::Tamara, 'kwt', ['api_token' => 'tok']));

        expect($gateway->createPayment(paymentRequest('150.500', 'KWD'))->gatewayReference)->toBe('tam-1');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), '"total_amount":{"amount":150.500,"currency":"KWD"}')
            && str_contains($request->body(), '"tax_amount":{"amount":0,"currency":"KWD"}'));
    });

    it('authorises then captures an approved order', function () {
        Http::fake([
            'api-sandbox.tamara.co/orders/tam-1' => Http::response(['order_id' => 'tam-1', 'status' => 'approved']),
            'api-sandbox.tamara.co/orders/tam-1/authorise' => Http::response(['status' => 'authorised']),
            'api-sandbox.tamara.co/payments/capture' => Http::response(['capture_id' => 'c1', 'status' => 'fully_captured']),
        ]);
        $gateway = new TamaraGateway(gatewayConfig(PaymentMethod::Tamara, 'uae', ['api_token' => 'tok']));

        expect($gateway->capture(paymentFor('tam-1', '500.00', 'AED'), Money::of('500.00', 'AED')))->status->toBe(PaymentStatus::Captured);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/authorise'));
    });

    it('accepts a notification with a valid token and refuses a forged or expired one', function () {
        $gateway = new TamaraGateway(gatewayConfig(PaymentMethod::Tamara, 'uae', ['notification_token' => 'notif-secret']));
        $body = json_encode(['order_id' => 'tam-1', 'order_reference_id' => 'ref-1', 'event_type' => 'order_approved']);

        $notification = $gateway->parseWebhook(webhookRequest($body, query: ['tamaraToken' => tamaraJwt('notif-secret', now()->addHour()->getTimestamp())]));
        expect($notification->eventId)->toBe('tam-1:order_approved')->and($notification->outcome->status)->toBe(PaymentStatus::Authorized);

        expect(fn () => $gateway->parseWebhook(webhookRequest($body, ['Authorization' => 'Bearer '.tamaraJwt('forged', now()->addHour()->getTimestamp())])))->toThrow(InvalidWebhookSignature::class)
            ->and(fn () => $gateway->parseWebhook(webhookRequest($body, query: ['tamaraToken' => tamaraJwt('notif-secret', now()->subMinute()->getTimestamp())])))->toThrow(InvalidWebhookSignature::class);
    });
});

it('turns gateway HTTP errors into a GatewayError', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

    (new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'uae', ['secret_key' => 'bad'])))->createPayment(paymentRequest('10.00', 'AED'));
})->throws(GatewayError::class, 'Invalid API Key');

it('refuses to call a gateway without its credentials', function () {
    (new StripeGateway(gatewayConfig(PaymentMethod::Stripe, 'uae', [])))->createPayment(paymentRequest('10.00', 'AED'));
})->throws(GatewayError::class, 'secret_key');
