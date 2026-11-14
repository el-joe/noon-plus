<?php

namespace App\Shared\Money;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Creates money values with the platform rounding policy (MASTER_PLAN ADR 3):
 * half-up to the currency's minor unit — 3 decimals for OMR, KWD, BHD and JOD,
 * 2 for AED, SAR, EGP and QAR. Never use floats for amounts.
 */
class MoneyFactory
{
    public function of(string $amount, string $currency): Money
    {
        return Money::of($amount, $currency, roundingMode: RoundingMode::HalfUp);
    }

    public function zero(string $currency): Money
    {
        return Money::zero($currency);
    }

    /**
     * Apply a percentage (e.g. "5.00" VAT) and round to the currency's minor unit.
     */
    public function percentageOf(Money $money, string $percentage): Money
    {
        return $money->multipliedBy(BigRational::of($percentage)->dividedBy(100), RoundingMode::HalfUp);
    }

    /**
     * Amount as a plain decimal string for storage in DECIMAL(19,4) columns.
     */
    public function toStorage(Money $money): string
    {
        return (string) $money->getAmount();
    }
}
