<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Enums\WebhookEventStatus;
use App\Domains\Payments\Models\CountryGatewayConfig;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Support\Gateways\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
});

/**
 * A pending Stripe payment of a placed order.
 */
function pendingStripePayment(string $price = '100.00'): Payment
{
    [, $token] = customerWithToken();
    $listing = sellableListing($price);
    test()->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);
    test()->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'stripe', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']])->assertCreated();
    app('auth')->forgetGuards();

    return Payment::query()->latest('id')->firstOrFail();
}

/**
 * POST a fake-gateway webhook signed with the fake secret.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeWebhook(array $payload, ?string $signature = null, string $gateway = 'stripe', string $market = 'uae-en'): TestResponse
{
    $body = json_encode($payload);

    return test()->call('POST', "/api/v1/{$market}/payments/webhooks/{$gateway}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.strtoupper(str_replace('-', '_', FakeGateway::HEADER)) => $signature ?? FakeGateway::sign($body),
    ], $body);
}

it('captures a payment from a signed webhook', function () {
    $payment = pendingStripePayment();

    fakeWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'])
        ->assertOk()->assertJsonPath('data.result', 'processed');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->order->fresh()->payment_status)->toBe('captured')
        ->and(PaymentWebhookEvent::query()->sole())->status->toBe(WebhookEventStatus::Processed)->payment_id->toBe($payment->id);
});

it('ignores a duplicate delivery of the same event', function () {
    $payment = pendingStripePayment();
    $event = ['id' => 'evt_dup', 'type' => 'checkout.session.completed', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'];

    fakeWebhook($event)->assertOk()->assertJsonPath('data.result', 'processed');
    fakeWebhook($event)->assertOk()->assertJsonPath('data.result', 'duplicate');
    fakeWebhook($event)->assertOk()->assertJsonPath('data.result', 'duplicate');

    expect(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and($payment->statusHistory()->where('to_status', 'captured')->count())->toBe(1);
});

it('treats the same event id from two gateways as two events', function () {
    $payment = pendingStripePayment();

    fakeWebhook(['id' => 'evt_same', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'])->assertJsonPath('data.result', 'processed');
    fakeWebhook(['id' => 'evt_same', 'gateway_reference' => 'unknown', 'status' => 'captured'], gateway: 'paytabs')->assertJsonPath('data.result', 'ignored');

    expect(PaymentWebhookEvent::query()->count())->toBe(2);
});

it('rejects an invalid signature and stores nothing', function () {
    $payment = pendingStripePayment();

    fakeWebhook(['id' => 'evt_bad', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured'], signature: str_repeat('0', 64))
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(PaymentWebhookEvent::query()->count())->toBe(0)->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects every fake webhook when no secret is configured (no built-in fallback secret)', function () {
    $payment = pendingStripePayment();
    $body = json_encode(['id' => 'evt_nosecret', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured']);
    $knownOldDefault = hash_hmac('sha256', $body, 'fake-webhook-secret');
    config(['nawy.payments.fake_webhook_secret' => null]);

    fakeWebhook(json_decode($body, true), signature: $knownOldDefault)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(PaymentWebhookEvent::query()->count())->toBe(0)->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(fn () => FakeGateway::sign($body))->toThrow(LogicException::class);
});

it('never moves a payment backwards on a late or out-of-order event', function () {
    $payment = pendingStripePayment();

    fakeWebhook(['id' => 'evt_a', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured']);
    fakeWebhook(['id' => 'evt_b', 'gateway_reference' => $payment->gateway_reference, 'status' => 'failed'])->assertOk();
    fakeWebhook(['id' => 'evt_c', 'gateway_reference' => $payment->gateway_reference, 'status' => 'pending'])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->order->fresh()->payment_status)->toBe('captured');
});

it('records events for unknown payments or informational types as ignored', function () {
    pendingStripePayment();

    fakeWebhook(['id' => 'evt_x', 'gateway_reference' => 'nobody', 'status' => 'captured'])->assertJsonPath('data.result', 'ignored');
    fakeWebhook(['id' => 'evt_y', 'type' => 'charge.updated'])->assertJsonPath('data.result', 'ignored');

    expect(PaymentWebhookEvent::query()->where('status', WebhookEventStatus::Ignored)->count())->toBe(2);
});

it('answers 404 for gateways the country does not use', function () {
    fakeWebhook(['id' => 'evt_1'], gateway: 'thawani')->assertNotFound();
    fakeWebhook(['id' => 'evt_1'], gateway: 'cod')->assertNotFound();
});

it('keeps webhook events in the country database of the market', function () {
    $payment = pendingStripePayment();
    fakeWebhook(['id' => 'evt_iso', 'gateway_reference' => $payment->gateway_reference, 'status' => 'captured']);

    $ksa = Country::query()->where('code', 'ksa')->sole();
    expect(app(CountryContext::class)->runAs($ksa, fn () => PaymentWebhookEvent::query()->count()))->toBe(0)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1);
});

it('processes a real Stripe-signed webhook end to end with the http driver', function () {
    $payment = pendingStripePayment();
    config(['nawy.payments.driver' => 'http']);
    CountryGatewayConfig::query()->where('gateway', PaymentMethod::Stripe)->where('country_id', Country::query()->where('code', 'uae')->value('id'))->sole()
        ->forceFill(['credentials' => ['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_e2e']])->save();
    $body = json_encode(['id' => 'evt_real', 'type' => 'checkout.session.completed', 'data' => ['object' => [
        'object' => 'checkout.session', 'id' => $payment->gateway_reference, 'client_reference_id' => $payment->reference,
        'payment_status' => 'paid', 'amount_total' => 10000, 'currency' => 'aed',
    ]]]);
    $t = now()->getTimestamp();
    $post = fn (string $secret) => $this->call('POST', '/api/v1/uae-en/payments/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret),
    ], $body);

    $post('whsec_wrong')->assertUnauthorized();
    $post('whsec_e2e')->assertOk()->assertJsonPath('data.result', 'processed');
    $post('whsec_e2e')->assertOk()->assertJsonPath('data.result', 'duplicate');

    expect($payment->fresh())->status->toBe(PaymentStatus::Captured)->captured_amount->toBe('100.0000');
});
