<?php

use App\Shared\Money\MoneyFactory;
use Brick\Money\Exception\MoneyMismatchException;

it('keeps three decimals for OMR, KWD, BHD and JOD and two for the other currencies', function (string $currency, string $expected) {
    $money = app(MoneyFactory::class)->of('10.12345', $currency);

    expect((string) $money->getAmount())->toBe($expected);
})->with([
    ['OMR', '10.123'],
    ['KWD', '10.123'],
    ['BHD', '10.123'],
    ['JOD', '10.123'],
    ['AED', '10.12'],
    ['SAR', '10.12'],
    ['EGP', '10.12'],
    ['QAR', '10.12'],
]);

it('rounds a percentage half up to the currency minor unit', function () {
    $factory = app(MoneyFactory::class);

    expect((string) $factory->percentageOf($factory->of('42.555', 'OMR'), '5.00')->getAmount())->toBe('2.128')
        ->and((string) $factory->percentageOf($factory->of('99.90', 'AED'), '5.00')->getAmount())->toBe('5.00');
});

it('refuses to add amounts in different currencies', function () {
    $factory = app(MoneyFactory::class);

    $factory->of('1', 'AED')->plus($factory->of('1', 'OMR'));
})->throws(MoneyMismatchException::class);
