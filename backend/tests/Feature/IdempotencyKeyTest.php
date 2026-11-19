<?php

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| QC-044: a client `Idempotency-Key` makes placing an order safe to retry
| (double click, flaky network): the retry gets the first answer, never a
| second order.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->actAsCountry('uae');
    [, $this->token] = customerWithToken();
    $this->withToken($this->token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => sellableListing('50.00')->id, 'quantity' => 1])->assertSuccessful();
    $this->order = ['payment_method' => 'cod', 'address' => ['name' => 'Sara', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2']];
});

it('places one order for any number of retries with the same key', function () {
    $first = $this->withToken($this->token)->withHeader('Idempotency-Key', 'checkout-attempt-0001')->postJson('/api/v1/uae-en/orders', $this->order)->assertCreated();
    $retry = $this->withToken($this->token)->withHeader('Idempotency-Key', 'checkout-attempt-0001')->postJson('/api/v1/uae-en/orders', $this->order);

    $retry->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($retry->json('data.number'))->toBe($first->json('data.number'))
        ->and(Order::query()->count())->toBe(1);
});

it('refuses a reused key with a different body, and a malformed key', function () {
    $this->withToken($this->token)->withHeader('Idempotency-Key', 'checkout-attempt-0002')->postJson('/api/v1/uae-en/orders', $this->order)->assertCreated();

    $this->withToken($this->token)->withHeader('Idempotency-Key', 'checkout-attempt-0002')->postJson('/api/v1/uae-en/orders', ['payment_method' => 'stripe'] + $this->order)
        ->assertUnprocessable()->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->withToken($this->token)->withHeader('Idempotency-Key', 'bad key!')->postJson('/api/v1/uae-en/orders', $this->order)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect(Order::query()->count())->toBe(1);
});

it('runs normally without the header', function () {
    $this->withToken($this->token)->postJson('/api/v1/uae-en/orders', $this->order)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
});

it('describes every API route in the generated OpenAPI document, with the Idempotency-Key where honoured', function () {
    $path = storage_path('framework/testing/openapi.json');
    $this->artisan('nawy:openapi', ['--path' => $path])->assertSuccessful();
    $spec = json_decode((string) file_get_contents($path), true);

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));
    foreach ($routes as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            expect($spec['paths']['/'.Str::after($route->uri(), 'api/v1/')][strtolower($method)] ?? null)->not->toBeNull();
        }
    }

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['paths']['/{market}/orders']['post']['parameters'])->toContain(['$ref' => '#/components/parameters/idempotencyKey'])
        ->and($spec['paths']['/{market}/orders']['post']['security'])->toBe([['bearer' => []]])
        ->and($spec['paths']['/{market}/auth/email-code/verify']['post']['requestBody']['content']['application/json']['schema']['required'])->toBe(['email', 'code', 'device_name']);
});
