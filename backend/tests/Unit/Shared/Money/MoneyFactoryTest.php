<?php

use App\Shared\Money\MoneyFactory;
use Brick\Money\Exception\UnknownCurrencyException;

it('rounds amounts half-up to the minor unit of each country currency', function (string $amount, string $currency, string $expected) {
    $money = (new MoneyFactory)->of($amount, $currency);

    expect((string) $money->getAmount())->toBe($expected);
})->with([
    'AED rounds the half cent up' => ['10.005', 'AED', '10.01'],
    'AED rounds below the half down' => ['10.0049', 'AED', '10.00'],
    'SAR pads to two decimals' => ['7', 'SAR', '7.00'],
    'EGP rounds the half up' => ['0.125', 'EGP', '0.13'],
    'QAR rounds down below half' => ['99.994', 'QAR', '99.99'],
    'OMR keeps three decimals' => ['1.2345', 'OMR', '1.235'],
    'KWD rounds below half down' => ['1.2344', 'KWD', '1.234'],
    'BHD pads to three decimals' => ['3.5', 'BHD', '3.500'],
    'JOD rounds the half up' => ['0.0005', 'JOD', '0.001'],
    'negative AED rounds half away from zero' => ['-2.345', 'AED', '-2.35'],
    'DECIMAL(19,4) storage value for OMR' => ['12.3456', 'OMR', '12.346'],
]);

it('adds decimal amounts exactly where floats would drift', function () {
    $factory = new MoneyFactory;

    $sum = $factory->of('0.1', 'AED')->plus($factory->of('0.2', 'AED'));

    expect((string) $sum->getAmount())->toBe('0.30');
});

it('creates a zero amount at the currency scale', function (string $currency, string $expected) {
    expect((string) (new MoneyFactory)->zero($currency)->getAmount())->toBe($expected);
})->with([
    ['AED', '0.00'],
    ['OMR', '0.000'],
]);

it('applies a percentage and rounds half-up to the currency', function (string $amount, string $currency, string $percentage, string $expected) {
    $factory = new MoneyFactory;

    $result = $factory->percentageOf($factory->of($amount, $currency), $percentage);

    expect((string) $result->getAmount())->toBe($expected);
})->with([
    '5% VAT on 100 AED' => ['100.00', 'AED', '5.00', '5.00'],
    '15% VAT on 10.10 SAR rounds 1.515 up' => ['10.10', 'SAR', '15', '1.52'],
    '5% on 0.10 AED rounds 0.005 up' => ['0.10', 'AED', '5', '0.01'],
    '5% on 0.09 AED rounds 0.0045 down' => ['0.09', 'AED', '5', '0.00'],
    '12.5% on 1.001 OMR rounds 0.125125 to 0.125' => ['1.001', 'OMR', '12.5', '0.125'],
    'a third percent keeps exact rational maths' => ['300.00', 'AED', '33.333', '100.00'],
    '0% is zero' => ['50.00', 'AED', '0', '0.00'],
    '100% is the whole amount' => ['12.345', 'KWD', '100', '12.345'],
]);

it('returns a plain decimal string for storage', function () {
    $factory = new MoneyFactory;

    expect($factory->toStorage($factory->of('1234.5', 'OMR')))->toBe('1234.500')
        ->and($factory->toStorage($factory->of('1234.5', 'AED')))->toBe('1234.50');
});

it('rejects an unknown currency code', function () {
    (new MoneyFactory)->of('1.00', 'XYZ');
})->throws(UnknownCurrencyException::class);
