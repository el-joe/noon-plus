<?php

namespace App\Shared\Money;

/**
 * Display format for panel amounts, e.g. "AED 1,234.50" / "OMR 12.345",
 * rounded with the platform policy and never through floats.
 */
class MoneyFormatter
{
    public function __construct(private MoneyFactory $money) {}

    public function format(string|int|null $amount, string $currency): string
    {
        $plain = (string) $this->money->of((string) ($amount ?? '0'), $currency)->getAmount();
        [$whole, $fraction] = array_pad(explode('.', ltrim($plain, '-')), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return (str_starts_with($plain, '-') ? '−' : '').$currency.' '.$grouped.($fraction !== '' ? '.'.$fraction : '');
    }
}
