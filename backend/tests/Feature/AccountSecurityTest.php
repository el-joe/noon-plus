<?php

use App\Domains\Identity\Enums\LoginOutcome;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(CountrySeeder::class));

it('lists signed-in devices with the current one marked', function () {
    $user = User::factory()->create();
    $phoneToken = $user->createToken('iPhone 15')->plainTextToken;
    $user->createToken('Chrome on Mac');

    $this->withToken($phoneToken)->getJson('/api/v1/uae-en/me/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'iPhone 15', 'is_current' => true])
        ->assertJsonFragment(['name' => 'Chrome on Mac', 'is_current' => false]);
});

it('signs another device out', function () {
    $user = User::factory()->create();
    $phoneToken = $user->createToken('iPhone 15')->plainTextToken;
    $laptop = $user->createToken('Chrome on Mac');

    $this->withToken($phoneToken)->deleteJson('/api/v1/uae-en/me/devices/'.$laptop->accessToken->id)->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPhone 15']);
});

it('returns 404 when revoking a device of another account', function () {
    $owner = User::factory()->create();
    $ownerToken = $owner->createToken('Owner phone')->plainTextToken;
    $strangerDevice = User::factory()->create()->createToken('Stranger phone');

    $this->withToken($ownerToken)->deleteJson('/api/v1/uae-en/me/devices/'.$strangerDevice->accessToken->id)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'device_not_found');

    expect($strangerDevice->accessToken->fresh())->not->toBeNull();
});

it('shows the most recent sign-ins first', function () {
    $user = User::factory()->create();
    $user->loginEvents()->create(['channel' => 'api', 'outcome' => LoginOutcome::WrongCode, 'email' => $user->email]);
    $this->travel(1)->minutes();
    $user->loginEvents()->create(['channel' => 'partner', 'outcome' => LoginOutcome::Succeeded, 'email' => $user->email]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/uae-en/me/login-history')
        ->assertOk()
        ->assertJsonPath('data.0.channel', 'partner')
        ->assertJsonPath('data.1.outcome', 'wrong_code');
});
