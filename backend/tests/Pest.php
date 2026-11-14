<?php

use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Support\InventoryLedger;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An active listing with stock in the current country (creates seller, product, variant).
 *
 * @param  array<string, mixed>  $attributes
 */
function sellableListing(string $price = '100.00', int $stock = 10, array $attributes = [], ?Vendor $vendor = null): Listing
{
    $listing = Listing::factory()
        ->for($vendor ?? Vendor::factory()->create())
        ->active()
        ->create(['price' => $price] + $attributes);

    if ($stock > 0) {
        app(InventoryLedger::class)->move($listing, $stock, 'restock');
    }

    return $listing;
}

/**
 * A verified customer with a Sanctum token.
 *
 * @return array{0: User, 1: string}
 */
function customerWithToken(bool $verified = true): array
{
    $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);

    return [$user, $user->createToken('test')->plainTextToken];
}
