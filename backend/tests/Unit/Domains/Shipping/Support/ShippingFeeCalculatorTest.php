<?php

use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Enums\SurchargeType;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingSurcharge;
use App\Domains\Shipping\Models\ShippingWeightSlab;
use App\Domains\Shipping\Support\ShippingFeeCalculator;
use App\Shared\Money\MoneyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\TestCase;

/*
| The active surcharges are read once per calculator from the database; the
| tests hand the calculator its surcharges in memory instead.
*/

uses(TestCase::class);

/**
 * @param  array<int, ShippingSurcharge>  $surcharges
 */
function shippingFeeCalculator(array $surcharges = []): ShippingFeeCalculator
{
    $calculator = new ShippingFeeCalculator(new MoneyFactory);
    (fn () => $this->activeSurcharges = new Collection($surcharges))->call($calculator);

    return $calculator;
}

/**
 * @param  array<int, array{0: string, 1: string}>  $slabs  [max_kg, fee]
 */
function shippingRate(string $baseFee = '10.00', string $baseWeightKg = '1.000', string $perKgFee = '2.50', array $slabs = []): ShippingRate
{
    $rate = (new ShippingRate)->forceFill([
        'shipping_zone_id' => 5, 'method' => ShippingMethod::Standard,
        'base_fee' => $baseFee, 'base_weight_kg' => $baseWeightKg, 'per_kg_fee' => $perKgFee,
    ]);

    return $rate->setRelation('slabs', new Collection(array_map(
        fn (array $slab): ShippingWeightSlab => (new ShippingWeightSlab)->forceFill(['max_kg' => $slab[0], 'fee' => $slab[1]]),
        $slabs,
    )));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function shippingSurcharge(SurchargeType $type, string $value, array $attributes = []): ShippingSurcharge
{
    return (new ShippingSurcharge)->forceFill($attributes + ['type' => $type, 'value' => $value, 'shipping_zone_id' => null, 'vendor_id' => null, 'method' => null, 'fulfilment' => null]);
}

it('charges the base fee plus the per-kg fee for every started kg over the base weight', function (int $grams, string $expected) {
    $fee = shippingFeeCalculator()->fee(shippingRate(), $grams, 'AED', 1, ['fbm']);

    expect((string) $fee->getAmount())->toBe($expected);
})->with([
    'within the base weight' => [800, '10.00'],
    'exactly the base weight' => [1000, '10.00'],
    'one gram over starts a kg' => [1001, '12.50'],
    'two and a half kg over' => [3500, '17.50'],
    'nothing to weigh' => [0, '10.00'],
]);

it('charges the fee of the first weight slab the shipment fits in', function (int $grams, string $expected) {
    $rate = shippingRate(perKgFee: '1.500', slabs: [['0.500', '1.000'], ['2.000', '2.000'], ['5.000', '3.500']]);

    $fee = shippingFeeCalculator()->fee($rate, $grams, 'OMR', 1, ['fbm']);

    expect((string) $fee->getAmount())->toBe($expected);
})->with([
    'first slab' => [400, '1.000'],
    'on the slab limit' => [500, '1.000'],
    'second slab' => [501, '2.000'],
    'last slab' => [5000, '3.500'],
    'above the last slab adds started kg over it' => [6200, '6.500'],
]);

it('adds percentage surcharges of the fee and fixed surcharges', function () {
    $calculator = shippingFeeCalculator([
        shippingSurcharge(SurchargeType::Percent, '12.5'),
        shippingSurcharge(SurchargeType::Fixed, '3.00'),
    ]);

    $fee = $calculator->fee(shippingRate(baseFee: '10.10'), 500, 'AED', 1, ['fbm']);

    expect((string) $fee->getAmount())->toBe('14.36');
});

it('adds only the surcharges matching the zone, seller, method and fulfilment', function (array $attributes, string $expected) {
    $calculator = shippingFeeCalculator([shippingSurcharge(SurchargeType::Fixed, '4.00', $attributes)]);

    $fee = $calculator->fee(shippingRate(), 500, 'AED', 7, ['fbn']);

    expect((string) $fee->getAmount())->toBe($expected);
})->with([
    'same zone' => [['shipping_zone_id' => 5], '14.00'],
    'other zone' => [['shipping_zone_id' => 6], '10.00'],
    'same seller' => [['vendor_id' => 7], '14.00'],
    'other seller' => [['vendor_id' => 8], '10.00'],
    'same method' => [['method' => ShippingMethod::Standard], '14.00'],
    'other method' => [['method' => ShippingMethod::Express], '10.00'],
    'fulfilment in the shipment' => [['fulfilment' => 'fbn'], '14.00'],
    'fulfilment not in the shipment' => [['fulfilment' => 'fbm'], '10.00'],
]);
