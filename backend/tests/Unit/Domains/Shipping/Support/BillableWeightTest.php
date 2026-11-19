<?php

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Shipping\Support\BillableWeight;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

/**
 * @param  array{0: int|string, 1: int|string, 2: int|string}|null  $dimensionsCm
 */
function shippingVariant(int $weightGrams, ?array $dimensionsCm = null): ProductVariant
{
    [$length, $width, $height] = $dimensionsCm ?? [null, null, null];

    return (new ProductVariant)->forceFill(['weight_grams' => $weightGrams, 'length_cm' => $length, 'width_cm' => $width, 'height_cm' => $height]);
}

it('computes the volumetric weight rounded up to the gram', function (?array $dimensions, int $divisor, int $expected) {
    expect((new BillableWeight)->volumetricGrams(shippingVariant(0, $dimensions), $divisor))->toBe($expected);
})->with([
    '30 x 20 x 10 cm at 5000' => [[30, 20, 10], 5000, 1200],
    'fractional result rounds up' => [[10, 10, 10], 3000, 334],
    'decimal dimensions' => [['10.5', '20', '2.5'], 5000, 105],
    'missing a dimension' => [[30, 20, null], 5000, 0],
    'no dimensions' => [null, 5000, 0],
    'zero divisor is ignored' => [[30, 20, 10], 0, 0],
]);

it('bills the heavier of actual and volumetric weight per line, times quantity', function () {
    $lines = [
        ['variant' => shippingVariant(500, [30, 20, 10]), 'quantity' => 2],
        ['variant' => shippingVariant(2000, [10, 10, 10]), 'quantity' => 3],
        ['variant' => shippingVariant(750), 'quantity' => 1],
    ];

    expect((new BillableWeight)->grams($lines, 5000))->toBe(1200 * 2 + 2000 * 3 + 750);
});

it('bills nothing for an empty shipment', function () {
    expect((new BillableWeight)->grams([], 5000))->toBe(0);
});
