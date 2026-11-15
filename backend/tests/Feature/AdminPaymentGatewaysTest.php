<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->uae = $this->actAsCountry('uae');
});

function uaeGateway(PaymentMethod $method): CountryGatewayConfig
{
    return CountryGatewayConfig::query()->where('country_id', Country::query()->where('code', 'uae')->value('id'))->where('gateway', $method)->sole();
}

/**
 * @return array<string, mixed>
 */
function gatewayPayload(array $overrides = []): array
{
    return $overrides + ['is_enabled' => '1', 'mode' => 'sandbox', 'currencies' => 'AED', 'min_amount' => '', 'max_amount' => '', 'sort_order' => '1', 'fee_percent' => '', 'fee_fixed' => ''];
}

it('seeds the §4a gateways with exactly one recommended per country', function () {
    $expected = ['uae' => 'stripe', 'omn' => 'thawani', 'ksa' => 'paytabs', 'egy' => 'paytabs', 'kwt' => 'paytabs', 'qat' => 'paytabs', 'bhr' => 'paytabs', 'jor' => 'paytabs'];

    foreach ($expected as $code => $gateway) {
        $configs = CountryGatewayConfig::query()->whereBelongsTo(Country::query()->where('code', $code)->sole())->get();
        expect($configs->where('is_recommended', true))->toHaveCount(1)
            ->and($configs->firstWhere('is_recommended', true)->gateway->value)->toBe($gateway)
            ->and($configs->every(fn ($config) => $config->mode === GatewayMode::Sandbox && $config->credentials === null))->toBeTrue();
    }

    expect(CountryGatewayConfig::query()->where('gateway', PaymentMethod::PayPal)->where('is_enabled', true)->count())->toBe(0);

    $this->seed(PaymentGatewaySeeder::class);
    expect(CountryGatewayConfig::query()->count())->toBe(24);
});

it('lets the database hold at most one recommended gateway per country', function () {
    DB::table('country_gateway_configs')->where('id', uaeGateway(PaymentMethod::PayTabs)->id)->update(['is_recommended' => true]);
})->throws(UniqueConstraintViolationException::class);

it('shows the gateways and the checkout preview to super admins and finance only', function () {
    $this->actingAs(adminAccount())->get(onPanel('admin', 'countries/payment-gateways'))
        ->assertOk()
        ->assertSee('Payment gateways · United Arab Emirates')
        ->assertSee('Customer checkout preview')
        ->assertSeeInOrder(['Credit / debit card', 'PayTabs', 'Cash on delivery']);

    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'countries/payment-gateways'))->assertOk();
    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'countries/payment-gateways'))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'operations'))->put(onPanel('admin', 'countries/payment-gateways/'.uaeGateway(PaymentMethod::Stripe)->id), gatewayPayload())->assertForbidden();
    $this->actingAs(adminAccount('ksa', 'finance'))->put(onPanel('admin', 'countries/payment-gateways/'.uaeGateway(PaymentMethod::Stripe)->id).'?country=uae', gatewayPayload())->assertNotFound();
    $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'settings'))->assertOk()->assertDontSee('Payment gateways');
    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'settings'))->assertOk()->assertSee('Payment gateways');
});

it('only edits gateways of the selected country', function () {
    $omanThawani = CountryGatewayConfig::query()->where('gateway', PaymentMethod::Thawani)->sole();

    $this->actingAs(adminAccount())->put(onPanel('admin', "countries/payment-gateways/{$omanThawani->id}").'?country=uae', gatewayPayload())->assertNotFound();
});

it('updates settings, stores credentials encrypted and never echoes them back', function () {
    $stripe = uaeGateway(PaymentMethod::Stripe);
    $admin = adminAccount('uae', 'finance');

    $this->actingAs($admin)->put(onPanel('admin', "countries/payment-gateways/{$stripe->id}"), gatewayPayload([
        'mode' => 'live', 'currencies' => 'aed, usd', 'min_amount' => '5', 'max_amount' => '20000.50', 'sort_order' => '2', 'fee_percent' => '2.5', 'fee_fixed' => '1',
        'credentials' => ['secret_key' => 'sk_live_TOPSECRET_123', 'webhook_secret' => 'whsec_TOPSECRET_456'],
    ]))->assertSessionHasNoErrors();

    $stripe->refresh();
    expect($stripe)->mode->toBe(GatewayMode::Live)->currencies->toBe(['AED', 'USD'])->min_amount->toBe('5.0000')->max_amount->toBe('20000.5000')
        ->and($stripe->fee_rule)->toEqual(['percent' => '2.5', 'fixed' => '1', 'payer' => 'platform'])
        ->and($stripe->credential('secret_key'))->toBe('sk_live_TOPSECRET_123')
        ->and(DB::table('country_gateway_configs')->where('id', $stripe->id)->value('credentials'))->not->toContain('TOPSECRET')
        ->and($stripe->toArray())->not->toHaveKey('credentials');

    $page = $this->actingAs($admin)->get(onPanel('admin', 'countries/payment-gateways'))->assertOk();
    $page->assertDontSee('TOPSECRET')->assertSee('Saved — leave blank to keep');

    $this->actingAs($admin)->put(onPanel('admin', "countries/payment-gateways/{$stripe->id}"), gatewayPayload(['mode' => 'live', 'credentials' => ['secret_key' => '', 'webhook_secret' => 'whsec_NEW']]))->assertSessionHasNoErrors();
    expect($stripe->fresh()->credential('secret_key'))->toBe('sk_live_TOPSECRET_123')->and($stripe->fresh()->credential('webhook_secret'))->toBe('whsec_NEW');

    $this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=100.00')->assertOk()->assertDontSee('TOPSECRET')->assertDontSee('whsec_NEW');
});

it('audits changes without the credential values', function () {
    $stripe = uaeGateway(PaymentMethod::Stripe);
    $admin = adminAccount();

    $this->actingAs($admin)->put(onPanel('admin', "countries/payment-gateways/{$stripe->id}"), gatewayPayload(['mode' => 'live', 'credentials' => ['secret_key' => 'sk_live_AUDIT']]));

    $logs = Activity::query()->where('log_name', 'payment_gateways')->where('subject_id', $stripe->id)->get();
    expect($logs->firstWhere('event', 'updated')->properties['attributes'])->toMatchArray(['mode' => 'live'])
        ->and($logs->firstWhere('event', 'credentials_updated'))->causer_id->toBe($admin->id)->properties->toArray()->toBe(['fields' => ['secret_key']])
        ->and($logs->toJson())->not->toContain('sk_live_AUDIT');
});

it('validates the form and does not flash credentials back', function () {
    $stripe = uaeGateway(PaymentMethod::Stripe);

    $this->actingAs(adminAccount())->put(onPanel('admin', "countries/payment-gateways/{$stripe->id}"), gatewayPayload([
        'mode' => 'production', 'currencies' => 'AE', 'min_amount' => '50', 'max_amount' => '10', 'credentials' => ['secret_key' => 'sk_FLASHME'],
    ]))->assertSessionHasErrors(['mode', 'currencies', 'max_amount']);

    expect(json_encode(session()->getOldInput()))->not->toContain('sk_FLASHME')
        ->and($stripe->fresh()->credentials)->toBeNull();
});

it('moves the recommendation so exactly one gateway stays recommended', function () {
    $admin = adminAccount();
    $paytabs = uaeGateway(PaymentMethod::PayTabs);

    $this->actingAs($admin)->post(onPanel('admin', "countries/payment-gateways/{$paytabs->id}/recommend"))->assertSessionHasNoErrors();

    expect(uaeGateway(PaymentMethod::PayTabs)->is_recommended)->toBeTrue()
        ->and(uaeGateway(PaymentMethod::Stripe)->is_recommended)->toBeFalse()
        ->and(CountryGatewayConfig::query()->whereBelongsTo($this->uae)->where('is_recommended', true)->count())->toBe(1)
        ->and(CountryGatewayConfig::query()->where('gateway', PaymentMethod::Thawani)->sole()->is_recommended)->toBeTrue();

    expect(array_column($this->getJson('/api/v1/uae-en/checkout/payment-methods?amount=250.00')->json('data'), 'code'))->toBe(['paytabs', 'stripe', 'tabby', 'tamara', 'cod']);
});

it('refuses to disable the recommended gateway or recommend a disabled one', function () {
    $admin = adminAccount();
    $stripe = uaeGateway(PaymentMethod::Stripe);
    $paypal = uaeGateway(PaymentMethod::PayPal);

    $this->actingAs($admin)->put(onPanel('admin', "countries/payment-gateways/{$stripe->id}"), gatewayPayload(['is_enabled' => '0']))->assertSessionHasErrors('is_enabled');
    $this->actingAs($admin)->post(onPanel('admin', "countries/payment-gateways/{$paypal->id}/recommend"))->assertSessionHasErrors('is_recommended');

    expect($stripe->fresh())->is_enabled->toBeTrue()->is_recommended->toBeTrue()
        ->and($paypal->fresh()->is_recommended)->toBeFalse();
});

it('adds a gateway disabled in sandbox, and the first gateway of a country becomes recommended', function () {
    $admin = adminAccount();
    $kuwait = Country::query()->where('code', 'kwt')->sole();

    $this->actingAs($admin)->post(onPanel('admin', 'countries/payment-gateways').'?country=kwt', ['gateway' => 'tabby'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(onPanel('admin', 'countries/payment-gateways').'?country=kwt', ['gateway' => 'tabby'])->assertSessionHasErrors('gateway');
    $this->actingAs($admin)->post(onPanel('admin', 'countries/payment-gateways').'?country=kwt', ['gateway' => 'cod'])->assertSessionHasErrors('gateway');

    expect(CountryGatewayConfig::query()->whereBelongsTo($kuwait)->where('gateway', PaymentMethod::Tabby)->sole())
        ->is_enabled->toBeFalse()->mode->toBe(GatewayMode::Sandbox)->is_recommended->toBeFalse()->currencies->toBe(['KWD']);

    CountryGatewayConfig::query()->whereBelongsTo($kuwait)->delete();
    $this->actingAs($admin)->post(onPanel('admin', 'countries/payment-gateways').'?country=kwt', ['gateway' => 'stripe']);
    expect(CountryGatewayConfig::query()->whereBelongsTo($kuwait)->sole())->is_recommended->toBeTrue()->is_enabled->toBeTrue();
});
