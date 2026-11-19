<?php

use App\Shared\Money\MoneyFactory;
use App\Shared\Money\MoneyFormatter;

it('formats panel amounts with the currency, thousands separators and minor unit', function (string|int|null $amount, string $currency, string $expected) {
    $formatter = new MoneyFormatter(new MoneyFactory);

    expect($formatter->format($amount, $currency))->toBe($expected);
})->with([
    'AED with grouping' => ['1234.5', 'AED', 'AED 1,234.50'],
    'OMR with three decimals' => ['12.3456', 'OMR', 'OMR 12.346'],
    'millions' => ['1234567.891', 'KWD', 'KWD 1,234,567.891'],
    'below a thousand has no separator' => ['999.99', 'SAR', 'SAR 999.99'],
    'exactly a thousand' => ['1000', 'AED', 'AED 1,000.00'],
    'an integer amount' => [42, 'EGP', 'EGP 42.00'],
    'null formats as zero' => [null, 'BHD', 'BHD 0.000'],
    'negative amounts use the minus sign' => ['-1500.256', 'AED', '−AED 1,500.26'],
    'half-up rounding before display' => ['0.005', 'AED', 'AED 0.01'],
]);
